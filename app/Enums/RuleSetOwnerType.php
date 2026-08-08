<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleSetOwnerType: string
{
    case Client = 'client';
    case Brand = 'brand';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Cliente (corporativo)',
            self::Brand => 'Marca',
        };
    }
}
