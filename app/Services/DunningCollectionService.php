<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomerInvoice;
use App\Models\DunningLog;
use App\Notifications\DunningReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * E4 — Smart collections: e-mail dunning reminders for overdue invoices and log
 * each send. The escalation level follows how far past due the invoice is.
 */
final class DunningCollectionService
{
    /**
     * Send reminders for the given posted, overdue invoice ids. Skips invoices
     * with no balance, no overdue, or no customer e-mail. Sending is best-effort.
     *
     * @param  list<int>  $invoiceIds
     * @return array{sent: int, skipped: int, log: list<array<string,mixed>>}
     */
    public function send(array $invoiceIds, int $userId): array
    {
        $today = Carbon::today();
        $sent = 0;
        $skipped = 0;

        $invoices = CustomerInvoice::query()
            ->whereIn('id', $invoiceIds)
            ->where('status', 'posted')
            ->with('customer:id,name,email')
            ->get();

        foreach ($invoices as $invoice) {
            $balance = round((float) $invoice->total_amount - (float) $invoice->amount_paid, 2);
            $due = $invoice->due_date;
            $email = $invoice->customer?->email;

            if ($balance <= 0 || $due === null || Carbon::parse((string) $due)->gte($today) || ! $email) {
                $skipped++;
                continue;
            }

            $days = (int) Carbon::parse((string) $due)->diffInDays($today);
            $level = $days <= 30 ? 1 : ($days <= 60 ? 2 : 3);

            $ok = $this->safely(fn () => Notification::route('mail', $email)
                ->notify(new DunningReminderNotification($invoice, $level, (string) $invoice->customer?->name, $balance)));

            if (! $ok) {
                $skipped++;
                continue;
            }

            DunningLog::query()->create([
                'customer_id' => $invoice->customer_id,
                'customer_invoice_id' => $invoice->id,
                'level' => $level,
                'channel' => 'email',
                'balance' => $balance,
                'sent_to' => $email,
                'sent_by' => $userId,
            ]);
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'log' => $this->recent()];
    }

    /** @return list<array<string,mixed>> */
    public function recent(): array
    {
        return DunningLog::query()
            ->latest()->limit(50)
            ->get()
            ->map(fn (DunningLog $l): array => [
                'customer_invoice_id' => $l->customer_invoice_id,
                'level' => $l->level,
                'balance' => $l->balance,
                'sent_to' => $l->sent_to,
                'sent_at' => $l->created_at?->toIso8601String(),
            ])->all();
    }

    private function safely(callable $fn): bool
    {
        try {
            $fn();

            return true;
        } catch (Throwable $e) {
            Log::warning('Dunning reminder failed: '.$e->getMessage());

            return false;
        }
    }
}
