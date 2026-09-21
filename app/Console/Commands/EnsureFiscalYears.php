<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ensures a fiscal year (FYxxxx) and its 12 monthly periods exist for every year
 * in a range — the calendar groundwork for back-dated / historical postings.
 * Idempotent: existing years and periods are left untouched.
 */
final class EnsureFiscalYears extends Command
{
    protected $signature = 'finance:ensure-fiscal-years {--from=2015} {--to=2026} {--status=open : open|closed for newly created years}';

    protected $description = 'Create fiscal years + monthly periods for a range of years (idempotent).';

    public function handle(): int
    {
        $from = (int) $this->option('from');
        $to = (int) $this->option('to');
        $status = $this->option('status') === 'closed' ? 'closed' : 'open';

        if ($from > $to) {
            $this->error('--from must be <= --to.');

            return self::FAILURE;
        }

        $createdYears = 0;
        $createdPeriods = 0;

        DB::transaction(function () use ($from, $to, $status, &$createdYears, &$createdPeriods): void {
            foreach (range($from, $to) as $year) {
                $fy = FiscalYear::query()->firstOrCreate(
                    ['code' => 'FY'.$year],
                    [
                        'name' => 'Fiscal Year '.$year,
                        'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                        'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                        'status' => $status,
                    ],
                );
                if ($fy->wasRecentlyCreated) {
                    $createdYears++;
                }

                for ($month = 1; $month <= 12; $month++) {
                    $start = Carbon::create($year, $month, 1);
                    $period = FiscalPeriod::query()->firstOrCreate(
                        ['fiscal_year_id' => $fy->id, 'period_number' => $month],
                        [
                            'name' => $start->format('M Y'),
                            'start_date' => $start->toDateString(),
                            'end_date' => $start->copy()->endOfMonth()->toDateString(),
                            'status' => $fy->status === 'closed' ? 'closed' : 'open',
                        ],
                    );
                    if ($period->wasRecentlyCreated) {
                        $createdPeriods++;
                    }
                }
            }
        });

        $this->info("Fiscal calendar ensured for {$from}–{$to}: {$createdYears} year(s) and {$createdPeriods} period(s) created.");

        return self::SUCCESS;
    }
}
