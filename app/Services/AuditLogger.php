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

        // En el login de la API todavia no hay sesion: el actor es el sujeto.
        if ($user === null && $subject instanceof \App\Models\User) {
            $user = $subject;
        }

        return AuditLog::create([
            'brand_id' => $brandId ?? $this->atributo($subject, 'brand_id'),
            'client_id' => $clientId ?? $this->atributo($subject, 'client_id'),
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'action' => $action,
            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => self::limpiar($oldValues),
            'new_values' => self::limpiar($newValues),
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

    /** Claves que nunca se guardan en la bitacora, ni siquiera como hash. */
    private const SENSIBLES = ['password', 'remember_token', 'token', 'plain_text_token', 'api_key'];

    /**
     * @param  array<string, mixed>|null  $valores
     * @return array<string, mixed>|null
     */
    private static function limpiar(?array $valores): ?array
    {
        if ($valores === null) {
            return null;
        }

        foreach (array_keys($valores) as $clave) {
            if (in_array(strtolower((string) $clave), self::SENSIBLES, true)) {
                $valores[$clave] = '[omitido]';
            }
        }

        return $valores;
    }

    private function atributo(?Model $modelo, string $clave): mixed
    {
        if ($modelo === null) {
            return null;
        }

        $valor = $modelo->getAttributes()[$clave] ?? null;

        return is_numeric($valor) ? (int) $valor : null;
    }
}
