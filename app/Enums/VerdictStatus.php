<?php

declare(strict_types=1);

namespace App\Enums;

enum VerdictStatus: string
{
    case Approved = 'approved';
    case ApprovedWithObservations = 'approved_with_observations';
    case Rejected = 'rejected';
    case NotEvaluated = 'not_evaluated';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprobado',
            self::ApprovedWithObservations => 'Aprobado con observaciones',
            self::Rejected => 'Rechazado',
            self::NotEvaluated => 'Sin evaluar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::ApprovedWithObservations => 'warning',
            self::Rejected => 'danger',
            self::NotEvaluated => 'gray',
        };
    }

    /**
     * Un veredicto sin evaluacion no dice nada sobre la pieza.
     *
     * Sirve para que las pantallas y las metricas puedan excluirlo en vez de
     * contarlo como aprobacion: una pieza que nadie midio no es una pieza que
     * cumple.
     */
    public function esConcluyente(): bool
    {
        return $this !== self::NotEvaluated;
    }
}
