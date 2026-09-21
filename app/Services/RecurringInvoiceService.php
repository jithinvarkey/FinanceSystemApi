<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RecurringInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recurring invoices/bills. Templates materialise a DRAFT customer invoice or
 * vendor bill each due period through the existing invoice services; the draft
 * then goes through the normal approval → post flow.
 */
final class RecurringInvoiceService
{
    public function __construct(
        private readonly CustomerInvoiceService $customerInvoices,
        private readonly VendorInvoiceService $vendorInvoices,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, int $userId, ?RecurringInvoice $template = null): RecurringInvoice
    {
        $start = Carbon::parse($data['start_date']);
        $template ??= new RecurringInvoice();
        $template->fill([
            'type' => $data['type'],
            'party_id' => $data['party_id'],
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'day_of_month' => $data['day_of_month'] ?? (int) $start->day,
            'start_date' => $start->toDateString(),
            'next_run_date' => $template->exists ? $template->next_run_date : $start->toDateString(),
            'end_date' => $data['end_date'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'due_days' => $data['due_days'] ?? 30,
            'lines' => $data['lines'],
            'created_by' => $template->created_by ?? $userId,
        ]);
        $template->save();

        return $template;
    }

    /** Generate the next draft from a template immediately. */
    public function generateOne(RecurringInvoice $template, int $userId): string
    {
        return DB::transaction(function () use ($template, $userId): string {
            $runDate = Carbon::parse((string) $template->next_run_date);
            $number = $this->materialise($template, $runDate, $userId);

            $template->update([
                'next_run_date' => $template->frequency->nextAfter($runDate, $template->day_of_month)->toDateString(),
                'last_generated_at' => now(),
                'generated_count' => $template->generated_count + 1,
            ]);

            return $number;
        });
    }

    /**
     * Generate all templates due on/before $asOf.
     *
     * @return list<array{template: string, document: string}>
     */
    public function generateDue(Carbon $asOf, int $userId): array
    {
        $due = RecurringInvoice::query()
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', $asOf->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', DB::raw('next_run_date')))
            ->get();

        $out = [];
        foreach ($due as $template) {
            // A template may be multiple periods behind; catch up to $asOf.
            while ($template->is_active
                && Carbon::parse((string) $template->next_run_date)->lte($asOf)
                && ($template->end_date === null || Carbon::parse((string) $template->next_run_date)->lte(Carbon::parse((string) $template->end_date)))) {
                $out[] = ['template' => $template->name, 'document' => $this->generateOne($template, $userId)];
                $template->refresh();
            }
        }

        return $out;
    }

    private function materialise(RecurringInvoice $template, Carbon $runDate, int $userId): string
    {
        $due = $runDate->copy()->addDays($template->due_days)->toDateString();
        $lines = $template->lines;

        if ($template->type === 'customer') {
            $invoice = $this->customerInvoices->createDraft([
                'customer_id' => $template->party_id,
                'invoice_date' => $runDate->toDateString(),
                'due_date' => $due,
                'reference' => $template->reference,
                'description' => $template->description ?? $template->name,
                'lines' => $lines,
            ], $userId);

            return $invoice->invoice_number;
        }

        $invoice = $this->vendorInvoices->createDraft([
            'vendor_id' => $template->party_id,
            'vendor_invoice_no' => null,
            'invoice_date' => $runDate->toDateString(),
            'due_date' => $due,
            'reference' => $template->reference,
            'description' => $template->description ?? $template->name,
            'lines' => $lines,
        ], $userId);

        return $invoice->invoice_number;
    }
}
