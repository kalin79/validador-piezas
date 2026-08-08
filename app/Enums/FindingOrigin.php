<?php

declare(strict_types=1);

namespace App\Enums;

enum FindingOrigin: string
{
    case Deterministic = 'deterministic';
    case Ai = 'ai';
    case Human = 'human';

    public function label(): string
    {
        return match ($this) {
            self::Deterministic => 'Determinista',
            self::Ai => 'IA',
            self::Human => 'Humano',
        };
    }
}
