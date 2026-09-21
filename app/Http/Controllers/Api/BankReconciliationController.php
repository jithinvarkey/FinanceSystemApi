<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankReconciliationRequest;
use App\Models\ChartOfAccount;
use App\Services\BankReconciliationService;
use App\Services\BankStatementImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P5 / F20 — Bank reconciliation endpoints, incl. statement import &
 * auto-matching. {bankAccount} is a bank GL account.
 */
final class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $service,
        private readonly BankStatementImportService $statements,
    ) {
    }

    public function accounts(): JsonResponse
    {
        $this->authorize('banking.view');

        return response()->json(['data' => $this->service->accounts()]);
    }

    public function ledger(ChartOfAccount $bankAccount): JsonResponse
    {
        $this->authorize('banking.view');
        $this->assertBank($bankAccount);

        return response()->json(['data' => $this->service->ledger($bankAccount)]);
    }

    public function reconciliations(ChartOfAccount $bankAccount): JsonResponse
    {
        $this->authorize('banking.view');
        $this->assertBank($bankAccount);

        return response()->json(['data' => $this->service->history($bankAccount)]);
    }

    public function reconcile(StoreBankReconciliationRequest $request, ChartOfAccount $bankAccount): JsonResponse
    {
        $this->assertBank($bankAccount);

        $reconciliation = $this->service->reconcile($bankAccount, $request->validated(), (int) $request->user()->id);

        return response()->json(['data' => [
            'id' => $reconciliation->id,
            'statement_date' => $reconciliation->statement_date->toDateString(),
            'statement_balance' => $reconciliation->statement_balance,
            'cleared_total' => $reconciliation->cleared_total,
        ]], 201);
    }

    // ----- F20 — statement import & matching -----

    public function importStatement(Request $request, ChartOfAccount $bankAccount): JsonResponse
    {
        $this->authorize('banking.manage');
        $this->assertBank($bankAccount);

        $data = $request->validate(['csv' => ['required', 'string']]);
        $result = $this->statements->importCsv($bankAccount, $data['csv'], (int) $request->user()->id);

        return response()->json(['data' => $result], 201);
    }

    public function statementLines(ChartOfAccount $bankAccount): JsonResponse
    {
        $this->authorize('banking.view');
        $this->assertBank($bankAccount);

        return response()->json([
            'data' => $this->statements->lines($bankAccount),
            'matched_gl_ids' => $this->statements->matchedGlIds($bankAccount),
        ]);
    }

    public function autoMatch(ChartOfAccount $bankAccount): JsonResponse
    {
        $this->authorize('banking.manage');
        $this->assertBank($bankAccount);

        return response()->json([
            'data' => ['matched' => $this->statements->autoMatch($bankAccount)],
            'matched_gl_ids' => $this->statements->matchedGlIds($bankAccount),
        ]);
    }

    public function clearStatement(ChartOfAccount $bankAccount): JsonResponse
    {
        $this->authorize('banking.manage');
        $this->assertBank($bankAccount);

        return response()->json(['data' => ['cleared' => $this->statements->clear($bankAccount)]]);
    }

    private function assertBank(ChartOfAccount $account): void
    {
        abort_unless($account->is_bank_account && $account->is_postable, 404, 'Not a bank account.');
    }
}
