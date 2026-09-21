<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a business rule of the finance domain is violated
 * (e.g. unbalanced journal, posting to a closed period).
 *
 * Mapped to an HTTP status by the API exception handler (bootstrap/app.php):
 *  - 422 Unprocessable Entity — validation-style rule failures (default)
 *  - 409 Conflict            — illegal state transition / not editable
 *  - 403 Forbidden           — segregation-of-duties violation
 */
final class FinanceRuleException extends Exception
{
    /** HTTP status this rule violation maps to. */
    private int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    private function withStatus(int $status): self
    {
        $this->statusCode = $status;

        return $this;
    }

    public static function unbalanced(string $totalDebit, string $totalCredit): self
    {
        return new self("Posting rejected: debits ({$totalDebit}) do not equal credits ({$totalCredit}).");
    }

    public static function periodClosed(string $periodName): self
    {
        return (new self("Posting rejected: fiscal period {$periodName} is closed."))
            ->withStatus(Response::HTTP_CONFLICT);
    }

    public static function noPeriodForDate(string $date): self
    {
        return new self("Posting rejected: no fiscal period covers {$date}. Create the fiscal year first.");
    }

    public static function accountNotPostable(string $code): self
    {
        return new self("Posting rejected: account {$code} is a header or inactive account and cannot receive entries.");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return (new self("Action rejected: a journal in status '{$from}' cannot move to '{$to}'."))
            ->withStatus(Response::HTTP_CONFLICT);
    }

    public static function sodViolation(string $action): self
    {
        return (new self("Segregation of duties: you cannot {$action} a journal you created yourself."))
            ->withStatus(Response::HTTP_FORBIDDEN);
    }

    public static function notEditable(string $status): self
    {
        return (new self("Journal cannot be modified while in status '{$status}'. Only draft or rejected journals are editable."))
            ->withStatus(Response::HTTP_CONFLICT);
    }

    public static function emptyJournal(): self
    {
        return new self('A journal requires at least two lines forming a balanced entry.');
    }

    public static function mixedLine(int $lineNo): self
    {
        return new self("Line {$lineNo}: a line must carry either a debit or a credit amount, not both.");
    }

    // ----- Approval engine (P0.4) -----

    public static function noWorkflow(string $documentType): self
    {
        return new self("No active approval workflow is configured for '{$documentType}'.");
    }

    public static function approvalNotPending(): self
    {
        return (new self('This approval request has already been resolved.'))
            ->withStatus(Response::HTTP_CONFLICT);
    }

    public static function approvalPermission(string $permission): self
    {
        return (new self("You lack the '{$permission}' permission required to act on this step."))
            ->withStatus(Response::HTTP_FORBIDDEN);
    }

    public static function approvalSelf(): self
    {
        return (new self('Segregation of duties: you cannot approve a document you raised.'))
            ->withStatus(Response::HTTP_FORBIDDEN);
    }

    public static function approvalAlreadyActed(): self
    {
        return (new self('Segregation of duties: you have already acted on this request and cannot clear another step.'))
            ->withStatus(Response::HTTP_FORBIDDEN);
    }

    // ----- Accounts Payable (P3) -----

    public static function duplicateInvoice(string $vendorInvoiceNo): self
    {
        return (new self("Duplicate: an invoice numbered '{$vendorInvoiceNo}' already exists for this vendor."))
            ->withStatus(Response::HTTP_CONFLICT);
    }

    public static function noPayableAccount(): self
    {
        return new self('Posting rejected: configure a default payable account on the vendor first.');
    }

    // ----- Accounts Receivable (P4) -----

    public static function noReceivableAccount(): self
    {
        return new self('Posting rejected: configure a default receivable account on the customer first.');
    }

    public static function creditLimitExceeded(string $limit, string $exposure): self
    {
        return (new self("Credit limit exceeded: this invoice would take the customer's outstanding balance to {$exposure}, over the limit of {$limit}."))
            ->withStatus(Response::HTTP_CONFLICT);
    }
}
