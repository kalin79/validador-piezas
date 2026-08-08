<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ImmutableRecordException extends RuntimeException
{
    /**
     * @param  array<int, string>  $attributes
     */
    public static function forUpdate(string $model, array $attributes): self
    {
        return new self(sprintf(
            'El registro %s es inmutable. Intento de modificar: %s.',
            class_basename($model),
            implode(', ', $attributes)
        ));
    }

    public static function forDelete(string $model): self
    {
        return new self(sprintf(
            'El registro %s es evidencia de auditoria y no puede eliminarse.',
            class_basename($model)
        ));
    }
}
