<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\AssetDepreciationEntry;
use App\Models\AssetDisposal;
use App\Models\FixedAsset;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P7 — Fixed asset register + straight-line depreciation.
 *
 * The register just records the asset (acquisition is captured by the AP invoice
 * / opening balances, so no GL on create). A monthly depreciation run posts, per
 * eligible asset, Dr depreciation expense / Cr accumulated depreciation — capped
 * so net book value never drops below salvage — and logs one entry per asset per
 * month (so a period can't be charged twice).
 */
final class FixedAssetService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createAsset(array $data, int $userId): FixedAsset
    {
        return FixedAsset::query()->create([
            'asset_number' => $this->numbers->next('asset', Carbon::parse($data['acquisition_date'])),
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'acquisition_date' => $data['acquisition_date'],
            'cost' => round((float) $data['cost'], 2),
            'salvage_value' => round((float) ($data['salvage_value'] ?? 0), 2),
            'useful_life_months' => (int) $data['useful_life_months'],
            'depreciation_method' => in_array($data['depreciation_method'] ?? null, ['straight_line', 'reducing_balance'], true) ? $data['depreciation_method'] : 'straight_line',
            'is_cwip' => (bool) ($data['is_cwip'] ?? false),
            'accumulated_depreciation' => 0,
            'asset_account_id' => $data['asset_account_id'],
            'accum_depreciation_account_id' => $data['accum_depreciation_account_id'],
            'depreciation_expense_account_id' => $data['depreciation_expense_account_id'],
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'location' => $data['location'] ?? null,
            'custodian' => $data['custodian'] ?? null,
            'status' => AssetStatus::Active,
            'created_by' => $userId,
        ]);
    }

    /**
     * Run depreciation for the month containing $asOf. Idempotent per asset/month.
     *
     * @return array{period: string, assets: int, total: string}
     */
    public function runDepreciation(Carbon $asOf, int $userId): array
    {
        $year = (int) $asOf->year;
        $month = (int) $asOf->month;
        $entryDate = $asOf->copy()->endOfMonth();

        $assets = FixedAsset::query()
            ->where('status', AssetStatus::Active->value)
            ->where('is_cwip', false)   // F25 — CWIP doesn't depreciate until capitalised
            ->whereDate('acquisition_date', '<=', $entryDate->toDateString())
            ->whereDoesntHave('entries', fn ($q) => $q->where('period_year', $year)->where('period_month', $month))
            ->get();

        $total = 0.0;
        $count = 0;

        foreach ($assets as $asset) {
            $amount = min($this->periodCharge($asset), $asset->depreciableRemaining());
            $amount = round($amount, 2);
            if ($amount <= 0) {
                continue;
            }

            DB::transaction(function () use ($asset, $amount, $year, $month, $entryDate, $userId): void {
                $entry = AssetDepreciationEntry::query()->create([
                    'fixed_asset_id' => $asset->id,
                    'period_year' => $year,
                    'period_month' => $month,
                    'entry_date' => $entryDate->toDateString(),
                    'amount' => $amount,
                    'posted_by' => $userId,
                ]);

                $rows = $this->posting->post($entry, $entryDate, [
                    new PostingLine($this->resolvePostable((int) $asset->depreciation_expense_account_id), $amount, 0.0, 'SAR', 1.0, $asset->cost_center_id, 'Depreciation — '.$asset->name),
                    new PostingLine($this->resolvePostable((int) $asset->accum_depreciation_account_id), 0.0, $amount, 'SAR', 1.0, null, 'Accumulated depreciation — '.$asset->name),
                ], $userId);

                $entry->update(['batch_number' => $rows->first()->batch_number]);

                $asset->accumulated_depreciation = round((float) $asset->accumulated_depreciation + $amount, 2);
                if ($asset->depreciableRemaining() <= 0) {
                    $asset->status = AssetStatus::FullyDepreciated;
                }
                $asset->save();
            });

            $total = round($total + $amount, 2);
            $count++;
        }

        return [
            'period' => $asOf->format('Y-m'),
            'assets' => $count,
            'total' => number_format($total, 2, '.', ''),
        ];
    }

    /**
     * F24 — period charge by method. Straight-line = (cost − salvage) / life;
     * reducing-balance = double-declining (2 / life) on the current book value.
     */
    public function periodCharge(FixedAsset $asset): float
    {
        if ($asset->depreciation_method === 'reducing_balance') {
            if ($asset->useful_life_months <= 0) {
                return 0.0;
            }
            $rate = 2.0 / $asset->useful_life_months;

            return round($asset->bookValue() * $rate, 2);
        }

        return $asset->monthlyDepreciation();
    }

    /**
     * F23 — dispose of an asset (sale or scrap). Removes cost and accumulated
     * depreciation, books proceeds to the cash/receivable account, and the
     * balancing gain or loss to $gainLossAccountId.
     */
    public function dispose(FixedAsset $asset, float $proceeds, int $cashAccountId, int $gainLossAccountId, Carbon $date, int $userId, string $method = 'sale'): AssetDisposal
    {
        if ($asset->status === AssetStatus::Disposed) {
            throw new \App\Exceptions\FinanceRuleException('This asset is already disposed.');
        }

        $proceeds = round(max(0.0, $proceeds), 2);
        $cost = round((float) $asset->cost, 2);
        $accum = round((float) $asset->accumulated_depreciation, 2);
        $bookValue = round($cost - $accum, 2);
        $gainLoss = round($proceeds - $bookValue, 2);

        $cashId = $this->resolvePostable($cashAccountId);
        $glId = $this->resolvePostable($gainLossAccountId);

        return DB::transaction(function () use ($asset, $proceeds, $cost, $accum, $bookValue, $gainLoss, $cashId, $glId, $date, $userId, $method): AssetDisposal {
            $disposal = AssetDisposal::query()->create([
                'fixed_asset_id' => $asset->id,
                'disposal_date' => $date->toDateString(),
                'proceeds' => $proceeds,
                'book_value' => $bookValue,
                'gain_loss' => $gainLoss,
                'method' => $method,
                'created_by' => $userId,
            ]);

            $lines = [];
            if ($proceeds > 0) {
                $lines[] = new PostingLine($cashId, $proceeds, 0.0, 'SAR', 1.0, null, 'Disposal proceeds — '.$asset->name);
            }
            if ($accum > 0) {
                $lines[] = new PostingLine($this->resolvePostable((int) $asset->accum_depreciation_account_id), $accum, 0.0, 'SAR', 1.0, null, 'Remove accumulated depreciation — '.$asset->name);
            }
            $lines[] = new PostingLine($this->resolvePostable((int) $asset->asset_account_id), 0.0, $cost, 'SAR', 1.0, null, 'Remove asset cost — '.$asset->name);
            // Balancing gain (credit) or loss (debit).
            if ($gainLoss > 0) {
                $lines[] = new PostingLine($glId, 0.0, $gainLoss, 'SAR', 1.0, null, 'Gain on disposal — '.$asset->name);
            } elseif ($gainLoss < 0) {
                $lines[] = new PostingLine($glId, abs($gainLoss), 0.0, 'SAR', 1.0, null, 'Loss on disposal — '.$asset->name);
            }

            $rows = $this->posting->post($disposal, $date, $lines, $userId);
            $disposal->update(['batch_number' => $rows->first()->batch_number]);

            $asset->update(['status' => AssetStatus::Disposed]);

            return $disposal->fresh();
        });
    }

    /**
     * F25 — impair an asset: write its carrying value down by $amount.
     * Dr impairment loss / Cr accumulated depreciation.
     */
    public function impair(FixedAsset $asset, int $impairmentAccountId, float $amount, Carbon $date, int $userId): FixedAsset
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $asset->bookValue()) {
            throw new \App\Exceptions\FinanceRuleException('The impairment must be positive and not exceed the carrying value.');
        }
        $lossId = $this->resolvePostable($impairmentAccountId);

        DB::transaction(function () use ($asset, $amount, $lossId, $date, $userId): void {
            $this->posting->post($asset, $date, [
                new PostingLine($lossId, $amount, 0.0, 'SAR', 1.0, $asset->cost_center_id, 'Impairment loss — '.$asset->name),
                new PostingLine($this->resolvePostable((int) $asset->accum_depreciation_account_id), 0.0, $amount, 'SAR', 1.0, null, 'Impairment — '.$asset->name),
            ], $userId);

            $asset->increment('accumulated_depreciation', $amount);
        });

        return $asset->fresh();
    }

    /**
     * F25 — revalue an asset upward to a new carrying value. Dr asset cost /
     * Cr revaluation reserve (equity) for the uplift. (Downward → use impair.)
     */
    public function revalue(FixedAsset $asset, int $reserveAccountId, float $newCarryingValue, Carbon $date, int $userId): FixedAsset
    {
        $uplift = round($newCarryingValue - $asset->bookValue(), 2);
        if ($uplift <= 0) {
            throw new \App\Exceptions\FinanceRuleException('Upward revaluation only — the new value must exceed the carrying value. Use impairment to write down.');
        }
        $reserveId = $this->resolvePostable($reserveAccountId);

        DB::transaction(function () use ($asset, $uplift, $reserveId, $date, $userId): void {
            $this->posting->post($asset, $date, [
                new PostingLine($this->resolvePostable((int) $asset->asset_account_id), $uplift, 0.0, 'SAR', 1.0, null, 'Revaluation uplift — '.$asset->name),
                new PostingLine($reserveId, 0.0, $uplift, 'SAR', 1.0, null, 'Revaluation reserve — '.$asset->name),
            ], $userId);

            $asset->increment('cost', $uplift);
        });

        return $asset->fresh();
    }

    /**
     * F25 — capitalise a CWIP asset: it stops being work-in-progress and begins
     * depreciating from the in-service date.
     */
    public function capitalize(FixedAsset $asset, Carbon $inServiceDate): FixedAsset
    {
        if (! $asset->is_cwip) {
            throw new \App\Exceptions\FinanceRuleException('This asset is not capital work-in-progress.');
        }

        $asset->update(['is_cwip' => false, 'acquisition_date' => $inServiceDate->toDateString(), 'status' => AssetStatus::Active]);

        return $asset->fresh();
    }
}
