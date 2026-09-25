<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivote de los accesos de un equipo (usuarios, clientes, marcas).
 *
 * Los equipos son el mecanismo de aislamiento entre clientes: agregar un
 * cliente a un equipo es dar acceso a todo ese cliente. Eloquent no dispara
 * eventos en attach/detach salvo que la relacion use un pivote propio, asi que
 * sin esta clase esos cambios no dejaban rastro.
 */
class AccesoDeEquipo extends Pivot
{
    public $incrementing = false;

    protected static function booted(): void
    {
        static::created(static fn (self $p) => $p->registrar('concedido'));
        static::deleted(static fn (self $p) => $p->registrar('revocado'));
    }

    private function registrar(string $cambio): void
    {
        $atributos = $this->getAttributes();

        app(AuditLogger::class)->log(
            action: 'team.access_changed',
            newValues: [
                'cambio' => $cambio,
                'tabla' => $this->getTable(),
                'team_id' => $atributos['team_id'] ?? null,
                'user_id' => $atributos['user_id'] ?? null,
                'client_id' => $atributos['client_id'] ?? null,
                'brand_id' => $atributos['brand_id'] ?? null,
            ],
            clientId: isset($atributos['client_id']) ? (int) $atributos['client_id'] : null,
            brandId: isset($atributos['brand_id']) ? (int) $atributos['brand_id'] : null,
        );
    }
}
