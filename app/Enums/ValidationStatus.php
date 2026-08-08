<?php

declare(strict_types=1);

namespace App\Enums;

enum ValidationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cola',
            self::Running => 'Procesando',
            self::Completed => 'Completada',
            self::Failed => 'Fallida',
        };
    }
}
