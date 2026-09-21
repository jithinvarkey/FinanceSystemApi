<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Export\SheetExporter;
use App\Services\FinancialStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P11 — Core financial statements from the GL: Trial Balance, Income Statement
 * and Balance Sheet. Read-only; gated on general-ledger.view.
 */
final class FinancialStatementController extends Controller
{
    public function __construct(
        private readonly FinancialStatementService $service,
        private readonly SheetExporter $exporter,
    ) {
    }

    /** Export any financial statement as .xlsx. */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('general-ledger.view');
        $report = (string) $request->string('report');
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;
        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        [$title, $headers, $rows] = match ($report) {
            'trial-balance' => $this->trialRows($this->service->trialBalance($asOf)),
            'income-statement' => $this->incomeRows($this->service->incomeStatement($from, $to)),
            'balance-sheet' => $this->balanceRows($this->service->balanceSheet($asOf)),
            'cash-flow' => $this->cashRows($this->service->cashFlow($from, $to)),
            'account-ledger' => $this->ledgerRows($this->service->accountLedger((int) $request->integer('account_id'), $from, $to)),
            default => ['Report', [], []],
        };

        return $this->exporter->download("{$report}.xlsx", $title, $headers, $rows);
    }

    /** @return array{0:string,1:list<string>,2:list<list<mixed>>} */
    private function trialRows(array $d): array
    {
        $rows = array_map(fn ($r) => [$r['code'], $r['name'], $r['account_type'], $r['debit'], $r['credit']], $d['rows']);
        $rows[] = ['', '', 'TOTAL', $d['totals']['debit'], $d['totals']['credit']];

        return ['Trial Balance — as of '.$d['as_of'], ['Code', 'Account', 'Type', 'Debit', 'Credit'], $rows];
    }

    /** @return array{0:string,1:list<string>,2:list<list<mixed>>} */
    private function incomeRows(array $d): array
    {
        $rows = [['REVENUE', '']];
        foreach ($d['revenue'] as $r) {
            $rows[] = [$r['code'].' '.$r['name'], $r['amount']];
        }
        $rows[] = ['Total revenue', $d['revenue_total']];
        $rows[] = ['EXPENSES', ''];
        foreach ($d['expenses'] as $e) {
            $rows[] = [$e['code'].' '.$e['name'], $e['amount']];
        }
        $rows[] = ['Total expenses', $d['expenses_total']];
        $rows[] = ['Net profit', $d['net_profit']];

        return ['Income Statement', ['Account', 'Amount'], $rows];
    }

    /** @return array{0:string,1:list<string>,2:list<list<mixed>>} */
    private function balanceRows(array $d): array
    {
        $rows = [['ASSETS', '']];
        foreach ($d['assets'] as $a) {
            $rows[] = [$a['code'].' '.$a['name'], $a['amount']];
        }
        $rows[] = ['Total assets', $d['assets_total']];
        $rows[] = ['LIABILITIES', ''];
        foreach ($d['liabilities'] as $l) {
            $rows[] = [$l['code'].' '.$l['name'], $l['amount']];
        }
        $rows[] = ['Total liabilities', $d['liabilities_total']];
        $rows[] = ['EQUITY', ''];
        foreach ($d['equity'] as $e) {
            $rows[] = [$e['code'].' '.$e['name'], $e['amount']];
        }
        $rows[] = ['Current period earnings', $d['current_earnings']];
        $rows[] = ['Total liabilities + equity', $d['liabilities_equity_total']];

        return ['Balance Sheet — as of '.$d['as_of'], ['Account', 'Amount'], $rows];
    }

    /** @return array{0:string,1:list<string>,2:list<list<mixed>>} */
    private function cashRows(array $d): array
    {
        $rows = [];
        foreach (['operating', 'investing', 'financing'] as $sec) {
            $rows[] = [strtoupper($sec), ''];
            foreach ($d[$sec] as $line) {
                $rows[] = [$line['code'].' '.$line['name'], $line['amount']];
            }
            $rows[] = ['Net '.$sec, $d[$sec.'_total']];
        }
        $rows[] = ['Opening cash', $d['opening_cash']];
        $rows[] = ['Net change', $d['net_change']];
        $rows[] = ['Closing cash', $d['closing_cash']];

        return ['Cash Flow Statement', ['Account', 'Amount'], $rows];
    }

    /** @return array{0:string,1:list<string>,2:list<list<mixed>>} */
    private function ledgerRows(array $d): array
    {
        $rows = [['Opening', '', '', '', '', '', $d['opening']]];
        foreach ($d['rows'] as $r) {
            $rows[] = [$r['date'], $r['batch_number'], $r['source'], $r['description'], $r['debit'], $r['credit'], $r['balance']];
        }
        $rows[] = ['Closing', '', '', '', $d['totals']['debit'], $d['totals']['credit'], $d['closing']];

        return ['Account Ledger — '.$d['account']['code'].' '.$d['account']['name'], ['Date', 'Batch', 'Source', 'Description', 'Debit', 'Credit', 'Balance'], $rows];
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return response()->json(['data' => $this->service->trialBalance($asOf)]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        return response()->json(['data' => $this->service->incomeStatement($from, $to)]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return response()->json(['data' => $this->service->balanceSheet($asOf)]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        return response()->json(['data' => $this->service->cashFlow($from, $to)]);
    }

    /** GL account drill-down: every posted line on an account with a running balance. */
    public function accountLedger(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        return response()->json(['data' => $this->service->accountLedger((int) $data['account_id'], $from, $to)]);
    }
}
