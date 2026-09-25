<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ValidationStatus;
use App\Models\ValidationRun;

/**
 * Refresco automatico de las tablas mientras haya validaciones en curso.
 *
 * Con cola en segundo plano el veredicto llega despues de pintar la tabla.
 * Se consulta cada pocos segundos solo mientras exista alguna ejecucion
 * corriendo en las marcas del usuario; el resto del tiempo no hay polling.
 */
final class Refresco
{
    public static function mientrasHayaValidaciones(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $hay = ValidationRun::query()
            ->whereIn('brand_id', $user->accessibleBrandIds())
            ->whereIn('status', [ValidationStatus::Pending->value, ValidationStatus::Running->value])
            ->exists();

        return $hay ? '5s' : null;
    }
}
