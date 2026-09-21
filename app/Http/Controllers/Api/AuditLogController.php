<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P12 — Compliance & Audit: a read-only window onto the immutable audit trail
 * (who changed what, when). Gated on general-ledger.view (auditors hold it).
 */
final class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->filled('type'), fn ($q) => $q->where('auditable_type', 'App\\Models\\'.$request->string('type')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest()
            ->limit(300)
            ->get()
            ->map(fn (AuditLog $l): array => [
                'id' => $l->id,
                'event' => $l->event,
                'entity' => class_basename($l->auditable_type),
                'entity_id' => $l->auditable_id,
                'user' => $l->user?->name ?? 'system',
                'ip_address' => $l->ip_address,
                'changes' => $this->summariseChanges($l),
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $logs]);
    }

    /** Distinct entity types present, for the filter dropdown. */
    public function entities(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $types = AuditLog::query()->distinct()->pluck('auditable_type')
            ->map(fn (string $t): string => class_basename($t))->sort()->values();

        return response()->json(['data' => $types]);
    }

    /** Compact "field: old → new" list for changed fields (skips noise). */
    private function summariseChanges(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];
        $skip = ['updated_at', 'created_at'];
        $out = [];

        foreach (array_keys($new + $old) as $field) {
            if (in_array($field, $skip, true)) {
                continue;
            }
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;
            if ($before === $after) {
                continue;
            }
            $out[] = ['field' => $field, 'from' => $this->scalar($before), 'to' => $this->scalar($after)];
        }

        return array_slice($out, 0, 12);
    }

    private function scalar(mixed $v): ?string
    {
        if ($v === null || is_scalar($v)) {
            return $v === null ? null : (string) $v;
        }

        return json_encode($v);
    }
}
