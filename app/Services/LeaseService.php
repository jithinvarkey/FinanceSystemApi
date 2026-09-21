<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\Lease;
use App\Models\LeaseScheduleLine;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E14 — IFRS 16 leases. Recognises a right-of-use asset and lease liability at
 * the present value of the lease payments, then unwinds the liability and
 * depreciates the ROU asset month by month.
 */
final class LeaseService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    /**
     * Create a lease: compute the PV of the monthly payments (= initial liability
     * = ROU asset), post initial recognition (Dr ROU / Cr liability), and build
     * the amortisation schedule.
     */
    public function createLease(array $data, int $userId): Lease
    {
        $start = Carbon::parse($data['start_date'])->startOfMonth();
        $end = Carbon::parse($data['end_date'])->startOfMonth();
        if ($end->lt($start)) {
            throw new FinanceRuleException('Lease end must be on/after the start.');
        }

        $payment = round((float) $data['monthly_payment'], 2);
        $annualRate = (float) $data['discount_rate'];
        $monthlyRate = $annualRate / 100 / 12;
        $months = $this->monthList($start, $end);
        $n = count($months);

        // Present value of an ordinary annuity of $payment over $n months.
        $pv = $monthlyRate > 0
            ? $payment * (1 - (1 + $monthlyRate) ** (-$n)) / $monthlyRate
            : $payment * $n;
        $pv = round($pv, 2);
        $rouMonthly = round($pv / $n, 2);

        $rouId = $this->resolvePostable((int) $data['rou_asset_account_id']);
        $liabId = $this->resolvePostable((int) $data['lease_liability_account_id']);

        return DB::transaction(function () use ($data, $start, $end, $payment, $annualRate, $monthlyRate, $months, $n, $pv, $rouMonthly, $rouId, $liabId, $userId): Lease {
            $lease = Lease::query()->create([
                'reference' => $this->numbers->next('lease', $start),
                'description' => $data['description'], 'lessor' => $data['lessor'] ?? null,
                'start_date' => $start->toDateString(), 'end_date' => $end->endOfMonth()->toDateString(),
                'monthly_payment' => $payment, 'discount_rate' => $annualRate, 'initial_liability' => $pv,
                'rou_asset_account_id' => $data['rou_asset_account_id'], 'lease_liability_account_id' => $data['lease_liability_account_id'],
                'interest_expense_account_id' => $data['interest_expense_account_id'],
                'depreciation_expense_account_id' => $data['depreciation_expense_account_id'],
                'bank_account_id' => $data['bank_account_id'], 'status' => 'active', 'created_by' => $userId,
            ]);

            $opening = $pv;
            $rouAllocated = 0.0;
            foreach ($months as $i => $monthStart) {
                $interest = round($opening * $monthlyRate, 2);
                $principal = round($payment - $interest, 2);
                $closing = round($opening - $principal, 2);
                // Last month: depreciate the rounding remainder of the ROU asset.
                $rou = $i === $n - 1 ? round($pv - $rouAllocated, 2) : $rouMonthly;
                $rouAllocated = round($rouAllocated + $rou, 2);

                $lease->lines()->create([
                    'period_date' => $monthStart->copy()->endOfMonth()->toDateString(),
                    'opening_liability' => $opening, 'payment' => $payment, 'interest' => $interest,
                    'principal' => $principal, 'closing_liability' => max($closing, 0), 'rou_depreciation' => $rou, 'recognized' => false,
                ]);
                $opening = $closing;
            }

            // Initial recognition: Dr ROU asset / Cr lease liability.
            $this->posting->post($lease, $start, [
                new PostingLine($rouId, $pv, 0.0, 'SAR', 1.0, null, 'ROU asset — '.$lease->description),
                new PostingLine($liabId, 0.0, $pv, 'SAR', 1.0, null, 'Lease liability — '.$lease->description),
            ], $userId);

            return $lease->load('lines');
        });
    }

    /**
     * Post every due, unrecognised month up to $asOf. Each month:
     *   Dr interest expense, Dr lease liability (principal), Cr bank (payment)
     *   Dr ROU depreciation expense, Cr ROU asset.
     *
     * @return array{recognized: int, payments: string}
     */
    public function runMonthly(Carbon $asOf, int $userId): array
    {
        $lines = LeaseScheduleLine::query()
            ->where('recognized', false)
            ->whereDate('period_date', '<=', $asOf->toDateString())
            ->with('lease')
            ->orderBy('period_date')->get();

        $count = 0;
        $paid = 0.0;

        foreach ($lines as $line) {
            /** @var Lease $lease */
            $lease = $line->lease;
            DB::transaction(function () use ($lease, $line, $userId): void {
                $rows = $this->posting->post($lease, Carbon::parse((string) $line->period_date), [
                    new PostingLine($this->resolvePostable((int) $lease->interest_expense_account_id), (float) $line->interest, 0.0, 'SAR', 1.0, null, 'Lease interest — '.$lease->description),
                    new PostingLine($this->resolvePostable((int) $lease->lease_liability_account_id), (float) $line->principal, 0.0, 'SAR', 1.0, null, 'Lease principal — '.$lease->description),
                    new PostingLine($this->resolvePostable((int) $lease->bank_account_id), 0.0, (float) $line->payment, 'SAR', 1.0, null, 'Lease payment — '.$lease->description),
                    new PostingLine($this->resolvePostable((int) $lease->depreciation_expense_account_id), (float) $line->rou_depreciation, 0.0, 'SAR', 1.0, null, 'ROU depreciation — '.$lease->description),
                    new PostingLine($this->resolvePostable((int) $lease->rou_asset_account_id), 0.0, (float) $line->rou_depreciation, 'SAR', 1.0, null, 'ROU depreciation — '.$lease->description),
                ], $userId);

                $line->update(['recognized' => true, 'batch_number' => $rows->first()->batch_number]);
                if (! $lease->lines()->where('recognized', false)->exists()) {
                    $lease->update(['status' => 'completed']);
                }
            });

            $count++;
            $paid = round($paid + (float) $line->payment, 2);
        }

        return ['recognized' => $count, 'payments' => number_format($paid, 2, '.', '')];
    }

    /** @return list<Carbon> month-start dates start..end inclusive. */
    private function monthList(Carbon $start, Carbon $end): array
    {
        $out = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $out[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $out;
    }
}
