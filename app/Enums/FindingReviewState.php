<?php

declare(strict_types=1);

namespace App\Enums;

enum FindingReviewState: string
{
    case Unreviewed = 'unreviewed';
    case Confirmed = 'confirmed';
    case FalsePositive = 'false_positive';
    case AddedByHuman = 'added_by_human';

    public function label(): string
    {
        return match ($this) {
            self::Unreviewed => 'Sin revisar',
            self::Confirmed => 'Confirmado',
            self::FalsePositive => 'Falso positivo',
            self::AddedByHuman => 'Agregado por revisor',
        };
    }
}
