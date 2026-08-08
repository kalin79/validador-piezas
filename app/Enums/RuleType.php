<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleType: string
{
    case Deterministic = 'deterministic';
    case Judgment = 'judgment';

    public function label(): string
    {
        return match ($this) {
            self::Deterministic => 'Determinista (codigo)',
            self::Judgment => 'De juicio (IA)',
        };
    }
}
