<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Escribe la bitacora append-only. No usa el sistema de eventos de Eloquent
 * a proposito: queremos control explicito de que se registra y con que contexto.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $brandId = null,
        ?int $clientId = null,
    ): AuditLog {
        $user = Auth::user();

        return AuditLog::create([
            'brand_id' => $brandId ?? $subject?->brand_id ?? $user?->active_brand_id,
            'client_id' => $clientId ?? $subject?->client_id,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'action' => $action,
            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1000),
        ]);
    }

    public function logModelChange(string $action, Model $model): AuditLog
    {
        return $this->log(
            action: $action,
            subject: $model,
            oldValues: $model->wasRecentlyCreated ? null : $model->getOriginal(),
            newValues: $model->getAttributes(),
        );
    }
}
