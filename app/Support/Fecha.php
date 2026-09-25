<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Fechas para mostrar.
 *
 * La base guarda en UTC (config app.timezone) y asi debe seguir: es lo que
 * permite comparar registros sin ambiguedad y lo que esperan los respaldos.
 * Lo que cambia es como se MUESTRA: en la zona del usuario (America/Lima por
 * omision). Antes el panel mostraba las horas en UTC, cinco horas adelantadas.
 *
 * Las columnas ->dateTime() de Filament ya se convierten solas con
 * FilamentTimezone; esto cubre los ->format() escritos a mano en vistas.
 */
final class Fecha
{
    public static function zona(): string
    {
        return (string) config('app.display_timezone', 'America/Lima');
    }

    public static function local(?CarbonInterface $fecha): ?CarbonInterface
    {
        return $fecha?->copy()->setTimezone(self::zona());
    }
}
