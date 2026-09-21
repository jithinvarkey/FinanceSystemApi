<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P0.5 — Issues gap-free, race-safe document numbers.
 *
 * The counter row for a (type, branch, year) is locked FOR UPDATE inside a
 * transaction, so concurrent callers serialise and never collide or skip.
 * Defaults (prefix/padding/yearly) come from config/numbering.php on first use.
 */
final class DocumentNumberService
{
    /**
     * Reserve and format the next number for a document type.
     *
     * @param string $documentType e.g. 'journal_entry'
     * @param Carbon|null $date Document date — drives the year segment when the type resets yearly
     * @param int $branchId 0 = not branch-scoped
     */
    public function next(string $documentType, ?Carbon $date = null, int $branchId = 0): string
    {
        $config = $this->config($documentType);
        $year = $config['yearly'] ? (int) ($date?->year ?? now()->year) : 0;

        return DB::transaction(function () use ($documentType, $branchId, $year, $config): string {
            $sequence = $this->lockOrCreate($documentType, $branchId, $year, $config);

            $number = $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $this->format($sequence, $number);
        });
    }

    /**
     * Show what the next number would be without consuming it.
     */
    public function peek(string $documentType, ?Carbon $date = null, int $branchId = 0): string
    {
        $config = $this->config($documentType);
        $year = $config['yearly'] ? (int) ($date?->year ?? now()->year) : 0;

        $sequence = NumberSequence::query()
            ->where('document_type', $documentType)
            ->where('branch_id', $branchId)
            ->where('period_year', $year)
            ->first();

        if ($sequence === null) {
            $sequence = new NumberSequence([
                'prefix' => $config['prefix'],
                'padding' => $config['padding'],
                'include_year' => $config['yearly'],
                'separator' => (string) config('numbering.separator', '-'),
                'period_year' => $year,
                'next_number' => 1,
            ]);
        }

        return $this->format($sequence, $sequence->next_number);
    }

    /**
     * Fetch the counter row with a write lock, creating it if first-of-its-kind.
     * The create is retried against the unique key to survive a concurrent race.
     */
    private function lockOrCreate(string $documentType, int $branchId, int $year, array $config): NumberSequence
    {
        $find = fn () => NumberSequence::query()
            ->where('document_type', $documentType)
            ->where('branch_id', $branchId)
            ->where('period_year', $year)
            ->lockForUpdate()
            ->first();

        $sequence = $find();

        if ($sequence === null) {
            try {
                NumberSequence::query()->create([
                    'document_type' => $documentType,
                    'branch_id' => $branchId,
                    'period_year' => $year,
                    'prefix' => $config['prefix'],
                    'padding' => $config['padding'],
                    'include_year' => $config['yearly'],
                    'separator' => (string) config('numbering.separator', '-'),
                    'next_number' => 1,
                ]);
            } catch (QueryException) {
                // Lost the create race; the row now exists — fall through to re-fetch.
            }

            $sequence = $find();
        }

        return $sequence;
    }

    private function format(NumberSequence $sequence, int $number): string
    {
        $parts = [$sequence->prefix];

        if ($sequence->include_year && $sequence->period_year > 0) {
            $parts[] = (string) $sequence->period_year;
        }

        $parts[] = str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);

        return implode($sequence->separator, $parts);
    }

    /**
     * @return array{prefix: string, padding: int, yearly: bool}
     */
    private function config(string $documentType): array
    {
        /** @var array{prefix: string, padding: int, yearly: bool} $config */
        $config = config("numbering.types.{$documentType}", config('numbering.default'));

        return $config;
    }
}
