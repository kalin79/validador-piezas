<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * A que proveedor pertenece un modelo, segun config('ai.providers').
 *
 * Se deduce del identificador y no se configura aparte: el mismo dato no
 * puede quedar inconsistente consigo mismo.
 */
final class Proveedor
{
    public const ANTHROPIC = 'anthropic';

    public const OPENAI = 'openai';

    /** Clave del proveedor, o null si el modelo no pertenece a ninguno conocido. */
    public static function de(?string $modelo): ?string
    {
        if (blank($modelo)) {
            return null;
        }

        foreach ((array) config('ai.providers', []) as $clave => $p) {
            foreach ((array) ($p['prefixes'] ?? []) as $prefijo) {
                if (str_starts_with((string) $modelo, (string) $prefijo)) {
                    return (string) $clave;
                }
            }
        }

        return null;
    }

    public static function etiqueta(?string $clave): string
    {
        return $clave === null
            ? 'Otro'
            : (string) (config("ai.providers.{$clave}.label") ?? $clave);
    }
}
