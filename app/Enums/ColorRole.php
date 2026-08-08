<?php

declare(strict_types=1);

namespace App\Enums;

enum ColorRole: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Accent = 'accent';
    case Background = 'background';
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primario',
            self::Secondary => 'Secundario',
            self::Accent => 'Acento',
            self::Background => 'Fondo',
            self::Text => 'Texto',
        };
    }
}
