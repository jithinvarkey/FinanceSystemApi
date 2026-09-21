<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * FIN-0058 — Writes an immutable audit row for every lifecycle event
 * of audited finance models. Attach via Model::observe(AuditObserver::class).
 */
final class AuditObserver
{
    public function created(Model $model): void
    {
        $this->log($model, 'created', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $this->log($model, 'updated', $model->getOriginal(), $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', $model->getOriginal(), null);
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function log(Model $model, string $event, ?array $old, ?array $new): void
    {
        AuditLog::query()->create([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'user_id' => auth()->id(),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255),
        ]);
    }
}
