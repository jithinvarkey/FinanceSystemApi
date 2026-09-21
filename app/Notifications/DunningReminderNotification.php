<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CustomerInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E4 — dunning reminder e-mailed to a customer. Tone escalates by level:
 * 1 = courteous reminder, 2 = firm follow-up, 3 = final demand.
 */
final class DunningReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly CustomerInvoice $invoice,
        private readonly int $level,
        private readonly string $customerName,
        private readonly float $balance,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format($this->balance, 2).' SAR';
        $due = $this->invoice->due_date?->toDateString();

        $mail = (new MailMessage())->greeting('Dear '.$this->customerName.',');

        if ($this->level >= 3) {
            $mail->subject('FINAL DEMAND — invoice '.$this->invoice->invoice_number)
                ->error()
                ->line("Our records show invoice {$this->invoice->invoice_number} for {$amount} (due {$due}) remains unpaid and is now seriously overdue.")
                ->line('Please settle this immediately to avoid further action.');
        } elseif ($this->level === 2) {
            $mail->subject('Overdue payment reminder — invoice '.$this->invoice->invoice_number)
                ->line("This is a follow-up: invoice {$this->invoice->invoice_number} for {$amount} (due {$due}) is past due.")
                ->line('We would appreciate prompt settlement.');
        } else {
            $mail->subject('Payment reminder — invoice '.$this->invoice->invoice_number)
                ->line("A friendly reminder that invoice {$this->invoice->invoice_number} for {$amount} was due on {$due}.")
                ->line('If you have already paid, please disregard this message.');
        }

        return $mail->salutation('Regards, Diamond Insurance Broker — Finance');
    }
}
