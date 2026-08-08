<?php

declare(strict_types=1);

namespace App\Enums;

enum OverrideAction: string
{
    case Replace = 'replace';
    case Disable = 'disable';

    public function label(): string
    {
        return match ($this) {
            self::Replace => 'Reemplaza la regla heredada',
            self::Disable => 'Desactiva la regla heredada',
        };
    }
}
