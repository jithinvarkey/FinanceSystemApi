<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * F19 — sent to eligible approvers when a document enters their approval queue.
 */
final class ApprovalRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ApprovalRequest $request,
        private readonly string $requesterName,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'approval_requested',
            'title' => 'Approval needed',
            'message' => ucfirst(str_replace('_', ' ', $this->request->document_type)).
                ' for '.number_format((float) $this->request->amount, 2).' SAR awaits your approval.',
            'document_type' => $this->request->document_type,
            'approval_request_id' => $this->request->id,
            'amount' => (float) $this->request->amount,
            'requested_by' => $this->requesterName,
            'link' => '/approvals',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Approval needed — '.str_replace('_', ' ', $this->request->document_type))
            ->line($this->requesterName.' submitted a '.str_replace('_', ' ', $this->request->document_type).
                ' for '.number_format((float) $this->request->amount, 2).' SAR.')
            ->action('Review in approvals inbox', url('/approvals'))
            ->line('You are receiving this because you can approve this document type.');
    }
}
