<?php

declare(strict_types=1);

namespace App\Services\Posting;

/**
 * Immutable value object describing one ledger line submitted to GlPostingService.
 */
final readonly class PostingLine
{
    public function __construct(
        public int $accountId,
        public float $debit,
        public float $credit,
        public string $currencyCode,
        public float $exchangeRate = 1.0,
        public ?int $costCenterId = null,
        public ?string $description = null,
        public ?int $dimensionId = null, // F27 — extra GL analysis dimension
    ) {
    }

    public static function debit(int $accountId, float $amount, string $currency, float $rate = 1.0, ?int $costCenterId = null, ?string $description = null): self
    {
        return new self($accountId, $amount, 0.0, $currency, $rate, $costCenterId, $description);
    }

    public static function credit(int $accountId, float $amount, string $currency, float $rate = 1.0, ?int $costCenterId = null, ?string $description = null): self
    {
        return new self($accountId, 0.0, $amount, $currency, $rate, $costCenterId, $description);
    }
}
