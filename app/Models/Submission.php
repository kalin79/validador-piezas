<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Submission extends Model
{
    use BelongsToBrand;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quienes han cargado piezas dentro del alcance de quien consulta.
     *
     * Se parte de las cargas y no de la tabla de usuarios a proposito. Listar
     * usuarios directamente expondria los nombres del equipo de un cliente a
     * quien trabaja para otro, que es exactamente lo que el sistema promete
     * que no pasa. Al arrancar desde Submission, el scope de marca ya dejo
     * fuera todo lo que no corresponde.
     *
     * Son dos consultas y no una subconsulta anidada: asi el filtro por marca
     * queda aplicado por el scope de forma evidente, sin depender de como
     * Eloquent traduce una subconsulta a SQL.
     *
     * @return array<int, string>  id => nombre
     */
    public static function cargadores(): array
    {
        $ids = static::query()->distinct()->pluck('user_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
