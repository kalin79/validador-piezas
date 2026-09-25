<?php

declare(strict_types=1);

namespace App\Enums;

enum DirectorReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';
    // Quien envio lo retiro antes de que el director decidiera.
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente del director',
            self::Approved => 'Aprobada por el director',
            self::Returned => 'Devuelta por el director',
            self::Withdrawn => 'Envio retirado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Approved => 'success',
            self::Returned => 'danger',
            self::Withdrawn => 'gray',
        };
    }
}
