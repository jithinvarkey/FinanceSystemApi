<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories;
use App\Repositories\Contracts;
use Illuminate\Support\ServiceProvider;

/**
 * Binds repository contracts to Eloquent implementations.
 * Register in bootstrap/providers.php.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const BINDINGS = [
        Contracts\ChartOfAccountRepositoryInterface::class => Repositories\ChartOfAccountRepository::class,
        Contracts\FiscalYearRepositoryInterface::class => Repositories\FiscalYearRepository::class,
        Contracts\CurrencyRepositoryInterface::class => Repositories\CurrencyRepository::class,
        Contracts\ExchangeRateRepositoryInterface::class => Repositories\ExchangeRateRepository::class,
        Contracts\TaxCodeRepositoryInterface::class => Repositories\TaxCodeRepository::class,
        Contracts\CostCenterRepositoryInterface::class => Repositories\CostCenterRepository::class,
        Contracts\JournalEntryRepositoryInterface::class => Repositories\JournalEntryRepository::class,
        Contracts\RecurringJournalRepositoryInterface::class => Repositories\RecurringJournalRepository::class,
        Contracts\ApprovalRequestRepositoryInterface::class => Repositories\ApprovalRequestRepository::class,
        Contracts\VendorRepositoryInterface::class => Repositories\VendorRepository::class,
        Contracts\VendorInvoiceRepositoryInterface::class => Repositories\VendorInvoiceRepository::class,
        Contracts\VendorPaymentRepositoryInterface::class => Repositories\VendorPaymentRepository::class,
        Contracts\CustomerRepositoryInterface::class => Repositories\CustomerRepository::class,
        Contracts\CustomerInvoiceRepositoryInterface::class => Repositories\CustomerInvoiceRepository::class,
        Contracts\ReceiptRepositoryInterface::class => Repositories\ReceiptRepository::class,
        Contracts\PolicyRepositoryInterface::class => Repositories\PolicyRepository::class,
        Contracts\PolicyCancellationRepositoryInterface::class => Repositories\PolicyCancellationRepository::class,
        Contracts\PremiumCollectionRepositoryInterface::class => Repositories\PremiumCollectionRepository::class,
        Contracts\InsurerSettlementRepositoryInterface::class => Repositories\InsurerSettlementRepository::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }
}
