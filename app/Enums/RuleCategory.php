<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleCategory: string
{
    case Palette = 'palette';
    case Typography = 'typography';
    case Copy = 'copy';
    case Tone = 'tone';
    case Strategy = 'strategy';
    case Composition = 'composition';
    case Compliance = 'compliance';
    case RequiredAssets = 'required_assets';

    public function label(): string
    {
        return match ($this) {
            self::Palette => 'Paleta de colores',
            self::Typography => 'Tipografia',
            self::Copy => 'Redaccion',
            self::Tone => 'Tono',
            self::Strategy => 'Estrategia',
            self::Composition => 'Composicion',
            self::Compliance => 'Cumplimiento normativo',
            self::RequiredAssets => 'Activos obligatorios',
        };
    }

    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::Compliance => Severity::Blocking,
            self::Strategy => Severity::Minor,
            default => Severity::Major,
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
