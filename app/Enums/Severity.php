<?php

declare(strict_types=1);

namespace App\Enums;

enum Severity: string
{
    case Blocking = 'blocking';
    case Major = 'major';
    case Minor = 'minor';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Blocking => 'Bloqueante',
            self::Major => 'Mayor',
            self::Minor => 'Menor',
            self::Info => 'Informativa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Blocking => 'danger',
            self::Major => 'warning',
            self::Minor => 'info',
            self::Info => 'gray',
        };
    }

    public function defaultWeight(): float
    {
        return match ($this) {
            self::Blocking => 100.0,
            self::Major => 15.0,
            self::Minor => 5.0,
            self::Info => 0.0,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            []
        );
    }
}
