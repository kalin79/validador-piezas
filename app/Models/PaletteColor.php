<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ColorRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaletteColor extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => ColorRole::class,
            'lab' => 'array',
            'delta_e_tolerance' => 'float',
            'is_forbidden' => 'boolean',
        ];
    }

    public function palette(): BelongsTo
    {
        return $this->belongsTo(Palette::class);
    }

    /**
     * Tolerancia efectiva: la del color si existe, si no la de la paleta.
     *
     * No se usa $with = ['palette'] a proposito: provocaria que cargar
     * $palette->colors dispare una consulta de vuelta a la paleta por cada
     * color. Se resuelve la relacion solo si hace falta y no vino cargada.
     */
    public function effectiveTolerance(): float
    {
        if ($this->delta_e_tolerance !== null) {
            return (float) $this->delta_e_tolerance;
        }

        if ($this->relationLoaded('palette')) {
            return (float) ($this->getRelation('palette')?->default_delta_e_tolerance ?? 5.0);
        }

        return (float) (Palette::query()
            ->whereKey($this->palette_id)
            ->value('default_delta_e_tolerance') ?? 5.0);
    }
}
