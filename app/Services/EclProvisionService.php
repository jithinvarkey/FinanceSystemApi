<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EclRate;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E14 — IFRS 9 expected credit loss (ECL). Applies a loss-rate matrix to the AR
 * aging buckets to compute the required allowance, then books the movement
 * to reach that allowance (Dr impairment expense / Cr allowance, or reverse).
 */
final class EclProvisionService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly AccountsReceivableReportService $aging,
        private readonly GlPostingService $posting,
    ) {
    }

    /**
     * The provision matrix as of a date.
     *
     * @return array{as_of: string, rows: list<array{bucket: string, exposure: float, loss_rate: float, ecl: float}>, total_ecl: float}
     */
    public function matrix(Carbon $asOf): array
    {
        $totals = $this->aging->aging($asOf)['totals'];
        $rates = EclRate::query()->pluck('loss_rate', 'bucket');

        $rows = [];
        $total = 0.0;
        foreach (['current', '1_30', '31_60', '61_90', '91_120', '120_plus'] as $bucket) {
            $exposure = round((float) ($totals[$bucket] ?? 0), 2);
            $rate = (float) ($rates[$bucket] ?? 0);
            $ecl = round($exposure * $rate / 100, 2);
            $total = round($total + $ecl, 2);
            $rows[] = ['bucket' => $bucket, 'exposure' => $exposure, 'loss_rate' => $rate, 'ecl' => $ecl];
        }

        return ['as_of' => $asOf->toDateString(), 'rows' => $rows, 'total_ecl' => $total];
    }

    /**
     * Book the movement needed to bring the allowance account to the computed
     * ECL. Returns the posted delta (0 if no movement was needed).
     */
    public function post(Carbon $asOf, int $expenseAccountId, int $allowanceAccountId, int $userId): array
    {
        $targetEcl = $this->matrix($asOf)['total_ecl'];
        $expenseId = $this->resolvePostable($expenseAccountId);
        $allowanceId = $this->resolvePostable($allowanceAccountId);

        // Current allowance balance (contra-asset → credit-positive).
        $current = round((float) (DB::table('gl_transactions')
            ->where('account_id', $allowanceId)
            ->selectRaw('SUM(base_credit - base_debit) bal')->value('bal') ?? 0), 2);

        $delta = round($targetEcl - $current, 2);
        if (abs($delta) < 0.01) {
            return ['target_ecl' => $targetEcl, 'previous_allowance' => $current, 'movement' => '0.00', 'posted' => false];
        }

        // Use a persisted ECL-rate row as the posting source marker (real key, no FK on source).
        $source = EclRate::query()->firstOrFail();

        if ($delta > 0) {
            // Increase the allowance: Dr impairment expense / Cr allowance.
            $lines = [
                new PostingLine($expenseId, $delta, 0.0, 'SAR', 1.0, null, 'ECL impairment charge'),
                new PostingLine($allowanceId, 0.0, $delta, 'SAR', 1.0, null, 'ECL allowance'),
            ];
        } else {
            // Release: Dr allowance / Cr impairment expense.
            $amount = abs($delta);
            $lines = [
                new PostingLine($allowanceId, $amount, 0.0, 'SAR', 1.0, null, 'ECL allowance release'),
                new PostingLine($expenseId, 0.0, $amount, 'SAR', 1.0, null, 'ECL impairment release'),
            ];
        }

        $rows = $this->posting->post($source, $asOf, $lines, $userId);

        return ['target_ecl' => $targetEcl, 'previous_allowance' => $current, 'movement' => number_format($delta, 2, '.', ''), 'posted' => true, 'batch_number' => $rows->first()->batch_number];
    }
}
