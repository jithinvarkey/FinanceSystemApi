<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Repositories\Contracts\CurrencyRepositoryInterface;
use App\Repositories\Contracts\ExchangeRateRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIN-0003 — Currency rules: single base currency, dated rates,
 * and rate lookup with most-recent-effective fallback.
 */
final class CurrencyService
{
    public function __construct(
        private readonly CurrencyRepositoryInterface $currencies,
        private readonly ExchangeRateRepositoryInterface $rates,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Currency
    {
        return DB::transaction(function () use ($data): Currency {
            if (! empty($data['is_base'])) {
                $this->demoteExistingBase();
            }

            /** @var Currency $currency */
            $currency = $this->currencies->create($data);

            return $currency;
        });
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws FinanceRuleException When attempting to unset the only base currency
     */
    public function update(int $id, array $data): Currency
    {
        return DB::transaction(function () use ($id, $data): Currency {
            /** @var Currency $currency */
            $currency = $this->currencies->findByIdOrFail($id);

            if ($currency->is_base && array_key_exists('is_base', $data) && ! $data['is_base']) {
                throw new FinanceRuleException('Set another currency as base before removing this one.');
            }

            if (! empty($data['is_base']) && ! $currency->is_base) {
                $this->demoteExistingBase();
            }

            /** @var Currency $updated */
            $updated = $this->currencies->update($id, $data);

            return $updated;
        });
    }

    /**
     * Record a rate for a date (upsert per currency+date).
     *
     * @throws FinanceRuleException When a rate is supplied for the base currency
     */
    public function setRate(int $currencyId, string $date, float $rate, int $userId): ExchangeRate
    {
        /** @var Currency $currency */
        $currency = $this->currencies->findByIdOrFail($currencyId);

        if ($currency->is_base) {
            throw new FinanceRuleException('The base currency rate is fixed at 1.');
        }

        if ($rate <= 0) {
            throw new FinanceRuleException('Exchange rate must be greater than zero.');
        }

        return ExchangeRate::query()->updateOrCreate(
            ['currency_id' => $currencyId, 'rate_date' => $date],
            ['rate' => $rate, 'created_by' => $userId],
        );
    }

    /**
     * Effective rate for a currency on a date: latest rate on or before $date.
     *
     * @throws FinanceRuleException When no rate exists yet
     */
    public function rateFor(string $currencyCode, Carbon $date): float
    {
        /** @var Currency|null $currency */
        $currency = Currency::query()->where('code', $currencyCode)->first();

        if ($currency === null) {
            throw new FinanceRuleException("Unknown currency {$currencyCode}.");
        }

        if ($currency->is_base) {
            return 1.0;
        }

        $rate = $currency->exchangeRates()
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();

        if ($rate === null) {
            throw new FinanceRuleException("No exchange rate recorded for {$currencyCode} on or before {$date->toDateString()}.");
        }

        return (float) $rate->rate;
    }

    private function demoteExistingBase(): void
    {
        Currency::query()->where('is_base', true)->update(['is_base' => false]);
    }
}
