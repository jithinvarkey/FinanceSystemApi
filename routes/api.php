<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AccountsPayableReportController;
use App\Http\Controllers\Api\AccountsReceivableReportController;
use App\Http\Controllers\Api\AdminImportController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\BenefitController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\ProfitCenterController;
use App\Http\Controllers\Api\SalaryGradeController;
use App\Http\Controllers\Api\WorkforceController;
use App\Http\Controllers\Api\ExpenseClaimController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\CostCenterController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerAdvanceController;
use App\Http\Controllers\Api\AllocationController;
use App\Http\Controllers\Api\CommissionRuleController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\CustomerInvoiceController;
use App\Http\Controllers\Api\CustomerProfileController;
use App\Http\Controllers\Api\FxRevaluationController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\EclController;
use App\Http\Controllers\Api\KpiController;
use App\Http\Controllers\Api\ReportBuilderController;
use App\Http\Controllers\Api\LeaseController;
use App\Http\Controllers\Api\RevenueScheduleController;
use App\Http\Controllers\Api\GlDimensionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NumberingController;
use App\Http\Controllers\Api\AuditCommandController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CustomerNoteController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\TreasuryController;
use App\Http\Controllers\Api\BrokerReportController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BudgetRevisionController;
use App\Http\Controllers\Api\EndorsementController;
use App\Http\Controllers\Api\FinancialStatementController;
use App\Http\Controllers\Api\AssetRegisterController;
use App\Http\Controllers\Api\FixedAssetController;
use App\Http\Controllers\Api\InsurerSettlementController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\IntegrationEventController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\RecurringInvoiceController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\FiscalYearController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\LineOfBusinessController;
use App\Http\Controllers\Api\OpeningBalanceController;
use App\Http\Controllers\Api\PaymentRunController;
use App\Http\Controllers\Api\PettyCashController;
use App\Http\Controllers\Api\PolicyCancellationController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\PremiumCollectionController;
use App\Http\Controllers\Api\PremiumInstallmentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RecurringJournalController;
use App\Http\Controllers\Api\VatFilingController;
use App\Http\Controllers\Api\VatReturnController;
use App\Http\Controllers\Api\TaxCodeController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VendorProfileController;
use App\Http\Controllers\Api\VendorInvoiceController;
use App\Http\Controllers\Api\VendorNoteController;
use App\Http\Controllers\Api\VendorPaymentController;
use App\Http\Controllers\Api\YearEndCloseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication — token issuance (public) + session helpers (protected)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function (): void {
    // Public: brute-force throttled separately from the authenticated API.
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Inbound integration (P-INT) — PUSH endpoints for the upstream policy system
|--------------------------------------------------------------------------
| Machine auth via the X-Integration-Key header (NOT Sanctum). Every event
| lands as a DRAFT for finance review; nothing auto-posts. Idempotent by
| [source_system, external_id] — safe to retry.
*/
Route::prefix('v1/integration')->middleware(['integration.client', 'throttle:120,1'])->group(function (): void {
    Route::post('policies', [IntegrationController::class, 'policy']);
    Route::post('endorsements', [IntegrationController::class, 'endorsement']);
    Route::post('renewals', [IntegrationController::class, 'renewal']);
});

/*
|--------------------------------------------------------------------------
| Diamond Finance API — Module 1: Finance Configuration
|--------------------------------------------------------------------------
| All routes require Sanctum auth. Throttled at 60 req/min per user.
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {

    // Dashboards
    Route::get('dashboard/executive', [DashboardController::class, 'executive']);
    Route::get('dashboard/command-center', [DashboardController::class, 'commandCenter']);
    Route::get('dashboard/accountant', [DashboardController::class, 'accountant']);

    Route::prefix('finance-config')->group(function (): void {

        // FIN-0001 — Chart of accounts
        Route::get('accounts/tree', [ChartOfAccountController::class, 'tree']);
        Route::apiResource('accounts', ChartOfAccountController::class)
            ->parameters(['accounts' => 'chart_of_account']);

        // FIN-0002 / FIN-0040 — Fiscal years & period close
        Route::apiResource('fiscal-years', FiscalYearController::class)->only(['index', 'store']);
        Route::post('fiscal-periods/{period}/close', [FiscalYearController::class, 'closePeriod']);
        Route::post('fiscal-periods/{period}/reopen', [FiscalYearController::class, 'reopenPeriod']);

        // FIN-0003 — Currencies & exchange rates
        Route::apiResource('currencies', CurrencyController::class)->only(['index', 'store', 'update']);
        Route::post('currencies/{currency}/rates', [CurrencyController::class, 'storeRate']);

        // FIN-0004 — Tax codes
        Route::apiResource('tax-codes', TaxCodeController::class)->except(['show']);

        // FIN-0005 — Cost centers
        Route::apiResource('cost-centers', CostCenterController::class)->except(['show']);

        // F16 — Company/seller identity (ZATCA e-invoice + report headers)
        Route::get('company-settings', [CompanySettingController::class, 'show']);
        Route::put('company-settings', [CompanySettingController::class, 'update']);

        // F21 — Document numbering schemes & counters
        Route::get('numbering', [NumberingController::class, 'index']);
        Route::post('numbering', [NumberingController::class, 'upsert']);

        // F27 — GL analysis dimensions (master)
        Route::get('dimensions', [GlDimensionController::class, 'index']);
        Route::post('dimensions', [GlDimensionController::class, 'store']);
        Route::put('dimensions/{glDimension}', [GlDimensionController::class, 'update']);

        // E5 — Commission rule master + resolver
        Route::get('commission-rules', [CommissionRuleController::class, 'index']);
        Route::post('commission-rules', [CommissionRuleController::class, 'store']);
        Route::put('commission-rules/{commissionRule}', [CommissionRuleController::class, 'update']);
        Route::delete('commission-rules/{commissionRule}', [CommissionRuleController::class, 'destroy']);
        Route::post('commission-rules/resolve', [CommissionRuleController::class, 'resolve']);
    });

    Route::prefix('general-ledger')->group(function (): void {

        // FIN-0036/0037/0038 — Journals & workflow
        Route::apiResource('journals', JournalEntryController::class)
            ->parameters(['journals' => 'journal']);
        Route::post('journals/{journal}/submit', [JournalEntryController::class, 'submit']);
        Route::post('journals/{journal}/approve', [JournalEntryController::class, 'approve']);
        Route::post('journals/{journal}/reject', [JournalEntryController::class, 'reject']);
        Route::post('journals/{journal}/post', [JournalEntryController::class, 'post']);
        Route::post('journals/{journal}/reverse', [JournalEntryController::class, 'reverse']);

        // F22 — FX revaluation of foreign-currency monetary balances
        Route::get('fx-revaluation', [FxRevaluationController::class, 'index']);
        Route::post('fx-revaluation/preview', [FxRevaluationController::class, 'preview']);
        Route::post('fx-revaluation/post', [FxRevaluationController::class, 'post']);

        // E14 — IFRS 16 lease register
        Route::get('leases', [LeaseController::class, 'index']);
        Route::post('leases', [LeaseController::class, 'store']);
        Route::post('leases/run', [LeaseController::class, 'run']);

        // N5 — Profit-center P&L (profit per cost centre)
        Route::get('profit-centers/statement', [ProfitCenterController::class, 'statement']);

        // N4 — Self-service report builder
        Route::get('report-builder/definitions', [ReportBuilderController::class, 'index']);
        Route::post('report-builder/definitions', [ReportBuilderController::class, 'store']);
        Route::delete('report-builder/definitions/{reportDefinition}', [ReportBuilderController::class, 'destroy']);
        Route::post('report-builder/run', [ReportBuilderController::class, 'run']);
        Route::post('report-builder/drill', [ReportBuilderController::class, 'drill']);

        // N3 — KPI engine + role scorecards
        Route::get('kpis/library', [KpiController::class, 'library']);
        Route::get('kpis/scorecard', [KpiController::class, 'scorecard']);
        Route::put('kpis/targets', [KpiController::class, 'updateTargets']);

        // E14 — IFRS 9 expected credit loss (ECL) provision matrix
        Route::get('ecl/rates', [EclController::class, 'rates']);
        Route::put('ecl/rates', [EclController::class, 'updateRates']);
        Route::get('ecl/matrix', [EclController::class, 'matrix']);
        Route::post('ecl/post', [EclController::class, 'post']);

        // E8 — BI analytics cube + what-if
        Route::get('analytics/cube', [AnalyticsController::class, 'cube']);
        Route::post('analytics/what-if', [AnalyticsController::class, 'whatIf']);

        // E6 — Revenue recognition (IFRS 15) schedules
        Route::get('revenue-schedules', [RevenueScheduleController::class, 'index']);
        Route::post('revenue-schedules', [RevenueScheduleController::class, 'store']);
        Route::post('revenue-schedules/recognize', [RevenueScheduleController::class, 'recognize']);

        // F27 — Dimension analysis report
        Route::get('dimension-analysis', [GlDimensionController::class, 'analysis']);

        // F26 — Allocation journals (spread a cost across cost centres)
        Route::get('allocations', [AllocationController::class, 'index']);
        Route::post('allocations', [AllocationController::class, 'store']);
        Route::put('allocations/{allocationRule}', [AllocationController::class, 'update']);
        Route::delete('allocations/{allocationRule}', [AllocationController::class, 'destroy']);
        Route::post('allocations/{allocationRule}/run', [AllocationController::class, 'run']);

        // P2.9 — Opening balances (cutover trial balance)
        Route::get('opening-balances', [OpeningBalanceController::class, 'index']);
        Route::post('opening-balances', [OpeningBalanceController::class, 'store']);
        // P2.9b — Opening sub-ledger items (open AR / AP at cutover)
        Route::get('opening-balances/receivables', [OpeningBalanceController::class, 'receivables']);
        Route::post('opening-balances/receivables', [OpeningBalanceController::class, 'storeReceivable']);
        Route::get('opening-balances/payables', [OpeningBalanceController::class, 'payables']);
        Route::post('opening-balances/payables', [OpeningBalanceController::class, 'storePayable']);

        // P10 — Year-end close
        Route::get('year-end/preview', [YearEndCloseController::class, 'preview']);
        Route::post('year-end/close', [YearEndCloseController::class, 'close']);

        // P11 — Core financial statements
        Route::get('reports/trial-balance', [FinancialStatementController::class, 'trialBalance']);
        Route::get('reports/income-statement', [FinancialStatementController::class, 'incomeStatement']);
        Route::get('reports/balance-sheet', [FinancialStatementController::class, 'balanceSheet']);
        Route::get('reports/account-ledger', [FinancialStatementController::class, 'accountLedger']);
        Route::get('reports/cash-flow', [FinancialStatementController::class, 'cashFlow']);
        Route::get('reports/export', [FinancialStatementController::class, 'export']);

        // FIN-0039 — Recurring journals
        Route::apiResource('recurring-journals', RecurringJournalController::class)
            ->only(['index', 'store', 'show']);
        Route::post('recurring-journals/{recurring_journal}/pause', [RecurringJournalController::class, 'pause']);
        Route::post('recurring-journals/{recurring_journal}/resume', [RecurringJournalController::class, 'resume']);
        Route::post('recurring-journals/generate', [RecurringJournalController::class, 'generate']);
    });

    // Recurring invoices & bills
    Route::prefix('recurring-invoices')->group(function (): void {
        Route::get('/', [RecurringInvoiceController::class, 'index']);
        Route::post('/', [RecurringInvoiceController::class, 'store']);
        Route::post('generate-due', [RecurringInvoiceController::class, 'generateDue']);
        Route::put('{recurringInvoice}', [RecurringInvoiceController::class, 'update']);
        Route::post('{recurringInvoice}/pause', [RecurringInvoiceController::class, 'pause']);
        Route::post('{recurringInvoice}/resume', [RecurringInvoiceController::class, 'resume']);
        Route::post('{recurringInvoice}/generate', [RecurringInvoiceController::class, 'generate']);
    });

    // P9 — Tax: ZATCA VAT return (live computed report)
    Route::get('tax/vat-return', [VatReturnController::class, 'show']);

    // F15 — Tax: VAT filing workflow (draft → file → pay)
    Route::prefix('tax/vat-filings')->group(function (): void {
        Route::get('/', [VatFilingController::class, 'index']);
        Route::post('/', [VatFilingController::class, 'store']);
        Route::get('{vatReturn}', [VatFilingController::class, 'show']);
        Route::post('{vatReturn}/refresh', [VatFilingController::class, 'refresh']);
        Route::post('{vatReturn}/file', [VatFilingController::class, 'file']);
        Route::post('{vatReturn}/pay', [VatFilingController::class, 'pay']);
        Route::delete('{vatReturn}', [VatFilingController::class, 'destroy']);
    });

    // P-INT — Integration: read-only admin log over the PUSH ingest endpoints
    Route::get('integration-log', [IntegrationEventController::class, 'index']);
    Route::get('integration-log/clients', [IntegrationEventController::class, 'clients']);

    // P6 — Expenses: expense claims (draft → approve → post)
    Route::prefix('expenses')->group(function (): void {
        Route::get('claims', [ExpenseClaimController::class, 'index']);
        Route::post('claims', [ExpenseClaimController::class, 'store']);
        Route::get('claims/{expenseClaim}', [ExpenseClaimController::class, 'show']);
        Route::put('claims/{expenseClaim}', [ExpenseClaimController::class, 'update']);
        Route::delete('claims/{expenseClaim}', [ExpenseClaimController::class, 'destroy']);
        Route::post('claims/{expenseClaim}/submit', [ExpenseClaimController::class, 'submit']);
        Route::post('claims/{expenseClaim}/post', [ExpenseClaimController::class, 'post']);
    });

    // P8 — Budgeting (budget vs GL actuals)
    Route::prefix('budgets')->group(function (): void {
        Route::get('/', [BudgetController::class, 'index']);
        Route::post('/', [BudgetController::class, 'store']);
        Route::get('{budget}', [BudgetController::class, 'show']);
        Route::put('{budget}', [BudgetController::class, 'update']);
        Route::get('{budget}/vs-actual', [BudgetController::class, 'vsActual']);
        // E9 — Budget revisions & transfers (virements)
        Route::get('{budget}/revisions', [BudgetRevisionController::class, 'history']);
        Route::post('{budget}/revise', [BudgetRevisionController::class, 'revise']);
        Route::post('{budget}/transfer', [BudgetRevisionController::class, 'transfer']);
    });

    // P7 — Fixed assets + depreciation
    Route::prefix('fixed-assets')->group(function (): void {
        Route::get('/', [FixedAssetController::class, 'index']);
        Route::post('/', [FixedAssetController::class, 'store']);
        Route::post('run-depreciation', [FixedAssetController::class, 'runDepreciation']);
        Route::get('{fixedAsset}', [FixedAssetController::class, 'show']);
        // F23 / F25 — disposal, impairment, revaluation, CWIP capitalisation
        Route::post('{fixedAsset}/dispose', [FixedAssetController::class, 'dispose']);
        Route::post('{fixedAsset}/impair', [FixedAssetController::class, 'impair']);
        Route::post('{fixedAsset}/revalue', [FixedAssetController::class, 'revalue']);
        Route::post('{fixedAsset}/capitalize', [FixedAssetController::class, 'capitalize']);
        // E10 — transfers & maintenance log
        Route::get('{fixedAsset}/register-history', [AssetRegisterController::class, 'history']);
        Route::post('{fixedAsset}/transfer', [AssetRegisterController::class, 'transfer']);
        Route::post('{fixedAsset}/maintenance', [AssetRegisterController::class, 'maintenance']);
    });

    // P6 — Petty cash vouchers (draft → post)
    Route::prefix('petty-cash')->group(function (): void {
        Route::get('vouchers', [PettyCashController::class, 'index']);
        Route::post('vouchers', [PettyCashController::class, 'store']);
        Route::get('vouchers/{pettyCashVoucher}', [PettyCashController::class, 'show']);
        Route::put('vouchers/{pettyCashVoucher}', [PettyCashController::class, 'update']);
        Route::delete('vouchers/{pettyCashVoucher}', [PettyCashController::class, 'destroy']);
        Route::post('vouchers/{pettyCashVoucher}/post', [PettyCashController::class, 'post']);
    });

    // P12 — Compliance & Audit: read-only audit trail
    Route::get('compliance/audit-logs', [AuditLogController::class, 'index']);
    Route::get('compliance/audit-entities', [AuditLogController::class, 'entities']);
    Route::get('compliance/risk-scan', [RiskController::class, 'scan']);
    // G1 — Audit Command Center (governance overview)
    Route::get('compliance/audit-command', [AuditCommandController::class, 'overview']);

    // P-IMP — Admin bulk data import (per-entity Excel, dry-run then commit)
    Route::get('admin/imports', [AdminImportController::class, 'index']);
    Route::get('admin/imports/{key}/template', [AdminImportController::class, 'template']);
    Route::post('admin/imports/{key}/preview', [AdminImportController::class, 'preview']);
    Route::post('admin/imports/{key}/commit', [AdminImportController::class, 'commit']);

    // P5 — Banking & bank reconciliation
    Route::prefix('banking')->group(function (): void {
        Route::get('accounts', [BankReconciliationController::class, 'accounts']);
        Route::get('accounts/{bankAccount}/ledger', [BankReconciliationController::class, 'ledger']);
        Route::get('accounts/{bankAccount}/reconciliations', [BankReconciliationController::class, 'reconciliations']);
        Route::post('accounts/{bankAccount}/reconcile', [BankReconciliationController::class, 'reconcile']);
        // F20 — statement import & auto-matching
        Route::get('accounts/{bankAccount}/statement', [BankReconciliationController::class, 'statementLines']);
        Route::post('accounts/{bankAccount}/statement/import', [BankReconciliationController::class, 'importStatement']);
        Route::post('accounts/{bankAccount}/statement/auto-match', [BankReconciliationController::class, 'autoMatch']);
        Route::delete('accounts/{bankAccount}/statement', [BankReconciliationController::class, 'clearStatement']);

        // E7 — Treasury & cash forecasting
        Route::get('treasury/position', [TreasuryController::class, 'position']);
        Route::get('treasury/forecast', [TreasuryController::class, 'forecast']);
        Route::get('treasury/items', [TreasuryController::class, 'items']);
        Route::post('treasury/items', [TreasuryController::class, 'store']);
        Route::delete('treasury/items/{treasuryItem}', [TreasuryController::class, 'destroy']);
    });

    // Payroll W1 — payroll finance (settings, employees, runs)
    Route::prefix('payroll')->group(function (): void {
        Route::get('settings', [PayrollController::class, 'settings']);
        Route::put('settings', [PayrollController::class, 'updateSettings']);
        Route::get('employees', [PayrollController::class, 'employees']);
        Route::post('employees', [PayrollController::class, 'storeEmployee']);
        Route::put('employees/{payrollEmployee}', [PayrollController::class, 'updateEmployee']);
        Route::get('runs', [PayrollController::class, 'runs']);
        Route::post('runs', [PayrollController::class, 'storeRun']);
        Route::post('runs/off-cycle', [PayrollController::class, 'storeOffCycle']);
        Route::post('runs/final-settlement', [PayrollController::class, 'finalSettlement']);
        Route::get('runs/{payrollRun}', [PayrollController::class, 'showRun']);
        Route::get('runs/{payrollRun}/bank-file', [PayrollController::class, 'bankFile']);
        Route::post('runs/{payrollRun}/approve', [PayrollController::class, 'approveRun']);
        Route::post('runs/{payrollRun}/post', [PayrollController::class, 'postRun']);
        // W2 — employee benefits (entitlements, provision accrual, costs)
        Route::get('benefits', [BenefitController::class, 'index']);
        Route::post('benefits', [BenefitController::class, 'store']);
        Route::post('benefits/accrue', [BenefitController::class, 'accrue']);
        Route::post('benefits/{employeeBenefit}/cost', [BenefitController::class, 'recordCost']);
        // W3 — employee loans & advances (recovered through payroll)
        Route::get('loans', [LoanController::class, 'index']);
        Route::post('loans', [LoanController::class, 'disburse']);
        // W4 — workforce cost allocation & analytics
        Route::get('analytics', [WorkforceController::class, 'analytics']);
        // W8 — workforce budget vs actual
        Route::get('budget', [WorkforceController::class, 'budget']);
        Route::put('budget', [WorkforceController::class, 'setBudget']);
        // W5 — salary grades catalog
        Route::get('grades', [SalaryGradeController::class, 'index']);
        Route::post('grades', [SalaryGradeController::class, 'store']);
        Route::put('grades/{salaryGrade}', [SalaryGradeController::class, 'update']);
    });

    // N5 — Contract & incentive register
    Route::prefix('contracts')->group(function (): void {
        Route::get('/', [ContractController::class, 'index']);
        Route::get('expiring', [ContractController::class, 'expiring']);
        Route::post('/', [ContractController::class, 'store']);
        Route::put('{contract}', [ContractController::class, 'update']);
        Route::delete('{contract}', [ContractController::class, 'destroy']);
    });

    // E12 — Document management with versioning (polymorphic attachments)
    Route::prefix('documents')->group(function (): void {
        Route::get('/', [DocumentController::class, 'index']);
        Route::post('/', [DocumentController::class, 'store']);
        Route::get('{document}/versions', [DocumentController::class, 'versions']);
        Route::post('{document}/archive', [DocumentController::class, 'archive']);
        Route::get('versions/{documentVersion}/download', [DocumentController::class, 'download']);
    });

    // F17 / F18 — User & role administration (admin-only)
    Route::prefix('admin')->group(function (): void {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/roles', [UserController::class, 'roles']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::get('permissions', [RoleController::class, 'permissions']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });

    // F19 — In-app notification inbox
    Route::prefix('notifications')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('{id}/read', [NotificationController::class, 'markRead']);
        Route::post('read-all', [NotificationController::class, 'markAllRead']);
    });

    // P0.4 — Approval engine (maker-checker inbox & actions)
    Route::prefix('approvals')->group(function (): void {
        Route::get('pending', [ApprovalController::class, 'pending']);
        Route::get('/', [ApprovalController::class, 'index']);
        Route::get('{approval}', [ApprovalController::class, 'show']);
        Route::post('{approval}/approve', [ApprovalController::class, 'approve']);
        Route::post('{approval}/reject', [ApprovalController::class, 'reject']);
        Route::post('{approval}/cancel', [ApprovalController::class, 'cancel']);
    });

    // P0.6 — Document attachments
    Route::get('attachments', [AttachmentController::class, 'index']);
    Route::post('attachments', [AttachmentController::class, 'store']);
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // P3 — Accounts Payable: vendor master
    Route::prefix('accounts-payable')->group(function (): void {
        Route::get('vendors-expiring', [VendorController::class, 'expiring']);

        // N2 — Procurement-to-Pay (purchase orders, goods receipts, 3-way match)
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);

        // E11 — Vendor self-service portal (consolidated financial view). Before apiResource.
        Route::get('vendors/{vendor}/profile', [VendorProfileController::class, 'profile']);

        Route::apiResource('vendors', VendorController::class)->parameters(['vendors' => 'vendor']);
        Route::post('vendors/{vendor}/submit', [VendorController::class, 'submit']);
        Route::post('vendors/{vendor}/block', [VendorController::class, 'block']);
        Route::post('vendors/{vendor}/unblock', [VendorController::class, 'unblock']);

        // Vendor bank accounts (P3.4)
        Route::post('vendors/{vendor}/bank-accounts', [VendorController::class, 'addBankAccount']);
        Route::put('bank-accounts/{bankAccount}', [VendorController::class, 'updateBankAccount']);
        Route::post('bank-accounts/{bankAccount}/verify', [VendorController::class, 'verifyBankAccount']);
        Route::delete('bank-accounts/{bankAccount}', [VendorController::class, 'deleteBankAccount']);

        // Vendor invoices (P3.6–P3.10)
        Route::apiResource('vendor-invoices', VendorInvoiceController::class)->parameters(['vendor-invoices' => 'vendorInvoice']);
        Route::post('vendor-invoices/{vendorInvoice}/submit', [VendorInvoiceController::class, 'submit']);
        Route::post('vendor-invoices/{vendorInvoice}/post', [VendorInvoiceController::class, 'post']);
        Route::post('vendor-invoices/{vendorInvoice}/take-discount', [VendorInvoiceController::class, 'takeDiscount']);
        Route::post('vendor-invoices/{vendorInvoice}/withhold', [VendorInvoiceController::class, 'withhold']);
        Route::get('reports/withholding', [VendorInvoiceController::class, 'withholdingReport']);

        // AP batch payment run
        Route::get('outstanding-invoices', [PaymentRunController::class, 'outstanding']);
        Route::post('payment-runs', [PaymentRunController::class, 'run']);

        // AP credit & debit notes
        Route::get('vendor-notes', [VendorNoteController::class, 'index']);
        Route::post('vendor-notes', [VendorNoteController::class, 'store']);
        Route::get('vendor-notes/{vendorNote}', [VendorNoteController::class, 'show']);
        Route::put('vendor-notes/{vendorNote}', [VendorNoteController::class, 'update']);
        Route::delete('vendor-notes/{vendorNote}', [VendorNoteController::class, 'destroy']);
        Route::post('vendor-notes/{vendorNote}/submit', [VendorNoteController::class, 'submit']);
        Route::post('vendor-notes/{vendorNote}/post', [VendorNoteController::class, 'post']);

        // Vendor payments (P3.11–P3.15)
        Route::get('payable-invoices', [VendorPaymentController::class, 'payableInvoices']);
        Route::apiResource('vendor-payments', VendorPaymentController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy'])->parameters(['vendor-payments' => 'vendorPayment']);
        Route::post('vendor-payments/{vendorPayment}/submit', [VendorPaymentController::class, 'submit']);
        Route::post('vendor-payments/{vendorPayment}/post', [VendorPaymentController::class, 'post']);

        // Reports (P3.17) — AP aging & vendor statement
        Route::get('reports/aging', [AccountsPayableReportController::class, 'aging']);
        Route::get('reports/vendor-ledger/{vendor}', [AccountsPayableReportController::class, 'vendorLedger']);
    });

    // P4 — Accounts Receivable: customer master
    Route::prefix('accounts-receivable')->group(function (): void {
        Route::get('customers-expiring', [CustomerController::class, 'expiring']);

        // E2 — Customer 360 profile + collection activity log
        Route::get('customers/{customer}/profile', [CustomerProfileController::class, 'profile']);
        Route::post('customers/{customer}/activities', [CustomerProfileController::class, 'addActivity']);

        Route::apiResource('customers', CustomerController::class)->parameters(['customers' => 'customer']);
        Route::post('customers/{customer}/submit', [CustomerController::class, 'submit']);
        Route::post('customers/{customer}/block', [CustomerController::class, 'block']);
        Route::post('customers/{customer}/unblock', [CustomerController::class, 'unblock']);

        // Customer bank accounts (P4.4)
        Route::post('customers/{customer}/bank-accounts', [CustomerController::class, 'addBankAccount']);
        Route::put('bank-accounts/{bankAccount}', [CustomerController::class, 'updateBankAccount']);
        Route::post('bank-accounts/{bankAccount}/verify', [CustomerController::class, 'verifyBankAccount']);
        Route::delete('bank-accounts/{bankAccount}', [CustomerController::class, 'deleteBankAccount']);

        // Customer invoices (P4.6–P4.10)
        Route::apiResource('customer-invoices', CustomerInvoiceController::class)->parameters(['customer-invoices' => 'customerInvoice']);
        Route::post('customer-invoices/{customerInvoice}/submit', [CustomerInvoiceController::class, 'submit']);
        Route::post('customer-invoices/{customerInvoice}/post', [CustomerInvoiceController::class, 'post']);
        Route::get('customer-invoices/{customerInvoice}/zatca-qr', [CustomerInvoiceController::class, 'zatcaQr']);
        Route::post('customer-invoices/{customerInvoice}/write-off', [CustomerInvoiceController::class, 'writeOff']);

        // On-account customer receipts (advances)
        Route::get('customer-advances', [CustomerAdvanceController::class, 'index']);
        Route::post('customer-advances', [CustomerAdvanceController::class, 'store']);
        Route::post('customer-advances/{customerAdvance}/post', [CustomerAdvanceController::class, 'post']);
        Route::post('customer-advances/{customerAdvance}/apply', [CustomerAdvanceController::class, 'apply']);

        // AR credit & debit notes
        Route::get('customer-notes', [CustomerNoteController::class, 'index']);
        Route::post('customer-notes', [CustomerNoteController::class, 'store']);
        Route::get('customer-notes/{customerNote}', [CustomerNoteController::class, 'show']);
        Route::put('customer-notes/{customerNote}', [CustomerNoteController::class, 'update']);
        Route::delete('customer-notes/{customerNote}', [CustomerNoteController::class, 'destroy']);
        Route::post('customer-notes/{customerNote}/submit', [CustomerNoteController::class, 'submit']);
        Route::post('customer-notes/{customerNote}/post', [CustomerNoteController::class, 'post']);

        // Receipts (P4.11–P4.14)
        Route::get('receivable-invoices', [ReceiptController::class, 'receivableInvoices']);
        Route::apiResource('receipts', ReceiptController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::post('receipts/{receipt}/submit', [ReceiptController::class, 'submit']);
        Route::post('receipts/{receipt}/post', [ReceiptController::class, 'post']);

        // Reports (P4.18) — AR aging & customer statement
        Route::get('reports/aging', [AccountsReceivableReportController::class, 'aging']);
        Route::get('reports/dunning', [AccountsReceivableReportController::class, 'dunning']);
        // E4 — smart collections: e-mail reminders + log
        Route::post('dunning/send', [AccountsReceivableReportController::class, 'sendReminders']);
        Route::get('dunning/log', [AccountsReceivableReportController::class, 'dunningLog']);
        Route::get('reports/customer-ledger/{customer}', [AccountsReceivableReportController::class, 'customerLedger']);
    });

    // P4.17 — Policy & Commission (broker layer)
    Route::prefix('policies')->group(function (): void {
        // Reference data — registered before {policy} so paths match first.
        Route::apiResource('lines-of-business', LineOfBusinessController::class)
            ->only(['index', 'store', 'update', 'destroy'])->parameters(['lines-of-business' => 'lineOfBusiness']);
        Route::apiResource('products', ProductController::class)->only(['index', 'store', 'update', 'destroy']);

        // Endorsements (P4.17 §9) — literal-prefixed routes before {policy}.
        Route::get('endorsements/{endorsement}', [EndorsementController::class, 'show']);
        Route::put('endorsements/{endorsement}', [EndorsementController::class, 'update']);
        Route::delete('endorsements/{endorsement}', [EndorsementController::class, 'destroy']);
        Route::post('endorsements/{endorsement}/submit', [EndorsementController::class, 'submit']);
        Route::post('endorsements/{endorsement}/post', [EndorsementController::class, 'post']);

        // Cancellations (P4.17 §10) — literal-prefixed routes before {policy}.
        Route::get('cancellations/{cancellation}', [PolicyCancellationController::class, 'show']);
        Route::put('cancellations/{cancellation}', [PolicyCancellationController::class, 'update']);
        Route::delete('cancellations/{cancellation}', [PolicyCancellationController::class, 'destroy']);
        Route::post('cancellations/{cancellation}/submit', [PolicyCancellationController::class, 'submit']);
        Route::post('cancellations/{cancellation}/post', [PolicyCancellationController::class, 'post']);

        // N1 — Premium installment plans (literal-prefixed before {policy}).
        Route::get('installments', [PremiumInstallmentController::class, 'index']);
        Route::get('installments/due', [PremiumInstallmentController::class, 'due']);
        Route::post('installments', [PremiumInstallmentController::class, 'store']);
        Route::post('installments/{premiumInstallment}/payment', [PremiumInstallmentController::class, 'recordPayment']);

        // Premium collection (Slice B §8.3) — literal-prefixed before {policy}.
        Route::get('collectable-policies', [PremiumCollectionController::class, 'collectablePolicies']);
        Route::get('premium-collections', [PremiumCollectionController::class, 'index']);
        Route::post('premium-collections', [PremiumCollectionController::class, 'store']);
        Route::get('premium-collections/{premiumCollection}', [PremiumCollectionController::class, 'show']);
        Route::put('premium-collections/{premiumCollection}', [PremiumCollectionController::class, 'update']);
        Route::delete('premium-collections/{premiumCollection}', [PremiumCollectionController::class, 'destroy']);
        Route::post('premium-collections/{premiumCollection}/submit', [PremiumCollectionController::class, 'submit']);
        Route::post('premium-collections/{premiumCollection}/post', [PremiumCollectionController::class, 'post']);

        // Broker reports / bordereaux (Slice D §12) — literal-prefixed before {policy}.
        Route::get('reports/insurer-positions', [BrokerReportController::class, 'insurerPositions']);
        Route::get('reports/insurer-statement/{insurer}', [BrokerReportController::class, 'insurerStatement']);
        Route::get('reports/insurer-aging', [BrokerReportController::class, 'insurerAging']);
        Route::get('reports/customer-statement/{customer}', [BrokerReportController::class, 'customerStatement']);
        Route::get('reports/customer-aging', [BrokerReportController::class, 'customerAging']);

        // Insurer settlement (Slice B §8.4) — literal-prefixed before {policy}.
        Route::get('settleable-policies', [InsurerSettlementController::class, 'settleablePolicies']);
        Route::get('insurer-settlements', [InsurerSettlementController::class, 'index']);
        Route::post('insurer-settlements', [InsurerSettlementController::class, 'store']);
        Route::get('insurer-settlements/{insurerSettlement}', [InsurerSettlementController::class, 'show']);
        Route::put('insurer-settlements/{insurerSettlement}', [InsurerSettlementController::class, 'update']);
        Route::delete('insurer-settlements/{insurerSettlement}', [InsurerSettlementController::class, 'destroy']);
        Route::post('insurer-settlements/{insurerSettlement}/submit', [InsurerSettlementController::class, 'submit']);
        Route::post('insurer-settlements/{insurerSettlement}/post', [InsurerSettlementController::class, 'post']);

        // Renewals (Slice E §11) — literal route before {policy}.
        Route::get('expiring', [PolicyController::class, 'expiring']);

        // Policies (this prefix's root resource)
        Route::get('/', [PolicyController::class, 'index']);
        Route::post('/', [PolicyController::class, 'store']);
        Route::get('{policy}', [PolicyController::class, 'show']);
        Route::put('{policy}', [PolicyController::class, 'update']);
        Route::delete('{policy}', [PolicyController::class, 'destroy']);
        Route::post('{policy}/submit', [PolicyController::class, 'submit']);
        Route::post('{policy}/issue', [PolicyController::class, 'issue']);
        Route::post('{policy}/renew', [PolicyController::class, 'renew']);
        Route::get('{policy}/endorsements', [EndorsementController::class, 'index']);
        Route::post('{policy}/endorsements', [EndorsementController::class, 'store']);
        Route::get('{policy}/cancellations', [PolicyCancellationController::class, 'index']);
        Route::post('{policy}/cancellations', [PolicyCancellationController::class, 'store']);
    });
});
