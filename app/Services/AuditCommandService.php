<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Command Center — one governance overview aggregating control health,
 * exception monitoring, audit readiness and recent audit-trail activity.
 */
final class AuditCommandService
{
    /** Document tables that carry an approval workflow (for stale-draft detection). */
    private const WORKFLOW_TABLES = [
        'journal_entries' => 'Journals',
        'vendor_invoices' => 'Vendor bills',
        'customer_invoices' => 'Customer invoices',
        'vendor_payments' => 'Vendor payments',
        'receipts' => 'Receipts',
        'payroll_runs' => 'Payroll runs',
    ];

    public function __construct(private readonly RiskScanService $risk)
    {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $scan = $this->risk->scan();
        $highRisks = array_values(array_filter($scan['findings'], fn (array $f): bool => in_array($f['severity'], ['high', 'critical'], true)));

        $glDiff = $this->glImbalance();
        $staleDrafts = $this->staleDrafts(14);
        $periods = $this->periodCounts();
        $events30d = (int) AuditLog::query()->where('created_at', '>=', now()->subDays(30))->count();

        $exceptions = [];
        if (abs($glDiff) >= 0.01) {
            $exceptions[] = ['type' => 'gl_imbalance', 'severity' => 'critical', 'detail' => 'General ledger is out of balance by '.number_format($glDiff, 2).' SAR.'];
        }
        foreach ($staleDrafts as $row) {
            if ($row['count'] > 0) {
                $exceptions[] = ['type' => 'stale_drafts', 'severity' => 'medium', 'detail' => "{$row['count']} {$row['label']} stuck in draft/pending for 14+ days."];
            }
        }
        foreach ($highRisks as $f) {
            $exceptions[] = ['type' => 'risk', 'severity' => $f['severity'], 'detail' => $f['title'].' — '.$f['detail']];
        }

        $readiness = [
            ['check' => 'General ledger balanced', 'ok' => abs($glDiff) < 0.01],
            ['check' => 'An open fiscal period exists', 'ok' => $periods['open'] > 0],
            ['check' => 'No high/critical risk findings', 'ok' => $highRisks === []],
            ['check' => 'No documents stuck in draft', 'ok' => array_sum(array_column($staleDrafts, 'count')) === 0],
            ['check' => 'Audit trail active (30d)', 'ok' => $events30d > 0],
        ];

        return [
            'controls' => [
                'open_risks' => count($scan['findings']),
                'high_severity' => count($highRisks),
                'audit_events_30d' => $events30d,
                'open_periods' => $periods['open'],
                'closed_periods' => $periods['closed'],
                'gl_imbalance' => round($glDiff, 2),
            ],
            'readiness' => $readiness,
            'readiness_score' => (int) round(count(array_filter($readiness, fn ($r) => $r['ok'])) / max(count($readiness), 1) * 100),
            'exceptions' => $exceptions,
            'recent_activity' => $this->recentActivity(),
        ];
    }

    private function glImbalance(): float
    {
        return round((float) (DB::table('gl_transactions')->selectRaw('SUM(base_debit - base_credit) d')->value('d') ?? 0), 2);
    }

    /** @return list<array{table: string, label: string, count: int}> */
    private function staleDrafts(int $days): array
    {
        $cutoff = now()->subDays($days)->toDateTimeString();
        $out = [];
        foreach (self::WORKFLOW_TABLES as $table => $label) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
                continue;
            }
            $count = (int) DB::table($table)
                ->whereIn('status', ['draft', 'pending_approval', 'submitted'])
                ->where('created_at', '<=', $cutoff)->count();
            $out[] = ['table' => $table, 'label' => $label, 'count' => $count];
        }

        return $out;
    }

    /** @return array{open: int, closed: int} */
    private function periodCounts(): array
    {
        return [
            'open' => (int) DB::table('fiscal_periods')->where('status', 'open')->count(),
            'closed' => (int) DB::table('fiscal_periods')->where('status', 'closed')->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentActivity(): array
    {
        return AuditLog::query()->with('user:id,name')->latest('created_at')->limit(15)->get()
            ->map(fn (AuditLog $a): array => [
                'event' => $a->event,
                'entity' => class_basename((string) $a->auditable_type),
                'entity_id' => $a->auditable_id,
                'user' => $a->user?->name,
                'at' => $a->created_at?->toIso8601String(),
            ])->all();
    }
}
