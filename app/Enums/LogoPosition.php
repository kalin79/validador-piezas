<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zonas de la pieza donde el logotipo puede ubicarse.
 *
 * La pieza se divide en una malla de 3x3. Una posicion se considera cumplida
 * si el centro del logo cae dentro de la celda correspondiente.
 */
enum LogoPosition: string
{
    case TopLeft = 'top_left';
    case TopCenter = 'top_center';
    case TopRight = 'top_right';
    case MiddleLeft = 'middle_left';
    case Center = 'center';
    case MiddleRight = 'middle_right';
    case BottomLeft = 'bottom_left';
    case BottomCenter = 'bottom_center';
    case BottomRight = 'bottom_right';

    public function label(): string
    {
        return match ($this) {
            self::TopLeft => 'Superior izquierda',
            self::TopCenter => 'Superior centro',
            self::TopRight => 'Superior derecha',
            self::MiddleLeft => 'Medio izquierda',
            self::Center => 'Centro',
            self::MiddleRight => 'Medio derecha',
            self::BottomLeft => 'Inferior izquierda',
            self::BottomCenter => 'Inferior centro',
            self::BottomRight => 'Inferior derecha',
        };
    }

    /**
     * Celda de la malla 3x3 en la que cae un punto normalizado (0..1).
     */
    public static function fromPoint(float $x, float $y): self
    {
        $columna = match (true) {
            $x < 1 / 3 => 'left',
            $x < 2 / 3 => 'center',
            default => 'right',
        };

        $fila = match (true) {
            $y < 1 / 3 => 'top',
            $y < 2 / 3 => 'middle',
            default => 'bottom',
        };

        // El centro real es 'center' a secas, no 'middle_center'.
        if ($fila === 'middle' && $columna === 'center') {
            return self::Center;
        }

        return self::from($fila.'_'.$columna);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $c, self $case): array => $c + [$case->value => $case->label()],
            []
        );
    }
}
