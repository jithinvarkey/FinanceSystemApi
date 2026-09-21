<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ChartOfAccount;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\LineOfBusiness;
use App\Models\InsurerSettlement;
use App\Models\Policy;
use App\Models\PolicyCancellation;
use App\Models\PolicyEndorsement;
use App\Models\PremiumCollection;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\RecurringJournal;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Models whose lifecycle events feed the FIN-0058 audit trail.
     *
     * @var list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const AUDITED = [
        ChartOfAccount::class,
        CostCenter::class,
        Currency::class,
        ExchangeRate::class,
        FiscalYear::class,
        FiscalPeriod::class,
        JournalEntry::class,
        RecurringJournal::class,
        TaxCode::class,
        Vendor::class,
        VendorInvoice::class,
        VendorPayment::class,
        Customer::class,
        CustomerInvoice::class,
        Receipt::class,
        LineOfBusiness::class,
        Product::class,
        Policy::class,
        PolicyEndorsement::class,
        PolicyCancellation::class,
        PremiumCollection::class,
        InsurerSettlement::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        $this->registerRbac();
    }

    /**
     * P0.1 — Authorize any ability the user's roles grant.
     *
     * Returning true short-circuits to "allowed"; returning null lets the
     * request fall through to a policy (e.g. JournalEntryPolicy, which then
     * re-checks the underlying 'general-ledger.*' permission). This single
     * hook drives both the string-ability checks ($this->authorize('x'),
     * $user->can('x')) and the record-level policies.
     */
    private function registerRbac(): void
    {
        Gate::before(static fn (User $user, string $ability): ?bool => $user->hasPermission($ability) ? true : null);
    }
}
