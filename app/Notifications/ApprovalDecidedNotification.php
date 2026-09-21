<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * F19 — sent to the document's raiser when their request is approved or rejected.
 */
final class ApprovalDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ApprovalRequest $request,
        private readonly bool $approved,
        private readonly ?string $reason = null,
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
        $doc = str_replace('_', ' ', $this->request->document_type);

        return [
            'kind' => $this->approved ? 'approval_approved' : 'approval_rejected',
            'title' => $this->approved ? 'Approved' : 'Rejected',
            'message' => 'Your '.$doc.' for '.number_format((float) $this->request->amount, 2).' SAR was '.
                ($this->approved ? 'approved.' : 'rejected'.($this->reason ? ': '.$this->reason : '.')),
            'document_type' => $this->request->document_type,
            'approval_request_id' => $this->request->id,
            'approved' => $this->approved,
            'reason' => $this->reason,
            'link' => '/approvals',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $doc = str_replace('_', ' ', $this->request->document_type);
        $mail = (new MailMessage())
            ->subject(($this->approved ? 'Approved' : 'Rejected').' — '.$doc)
            ->line('Your '.$doc.' for '.number_format((float) $this->request->amount, 2).' SAR was '.
                ($this->approved ? 'approved.' : 'rejected.'));
        if (! $this->approved && $this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail->action('Open approvals', url('/approvals'));
    }
}
