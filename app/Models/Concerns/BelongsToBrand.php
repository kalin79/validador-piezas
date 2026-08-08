<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Restringe cada consulta a las marcas que el usuario autenticado puede ver.
 *
 * El filtro usa los accesos derivados de los equipos, no la marca activa:
 * la marca activa es contexto de trabajo, no permiso.
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope('brand', static function (Builder $query): void {
            $user = Auth::user();

            // Sin usuario autenticado (consola, seeders, colas) no se filtra.
            // Todo comando de consola debe acotar por marca de forma explicita.
            if ($user === null || $user->hasGlobalAccess()) {
                return;
            }

            $query->whereIn(
                $query->getModel()->getTable().'.brand_id',
                $user->accessibleBrandIds()
            );
        });

        static::creating(static function ($model): void {
            if ($model->brand_id === null) {
                $model->brand_id = Auth::user()?->active_brand_id;
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function scopeForBrand(Builder $query, int $brandId): Builder
    {
        return $query->where($query->getModel()->getTable().'.brand_id', $brandId);
    }
}
