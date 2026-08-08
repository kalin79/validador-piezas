<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RuleSet extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'owner_type' => RuleSetOwnerType::class,
            'status' => RuleSetStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class)->orderBy('sort_order')->orderBy('code');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function owner(): Client|Brand|null
    {
        return $this->owner_type === RuleSetOwnerType::Client
            ? Client::find($this->owner_id)
            : Brand::find($this->owner_id);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', RuleSetStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === RuleSetStatus::Published;
    }

    public function isEditable(): bool
    {
        return $this->status === RuleSetStatus::Draft;
    }

    /**
     * Siguiente numero de version disponible para este dueno.
     *
     * Cuenta tambien las versiones borradas logicamente. El indice unico de
     * la base de datos no distingue entre una fila viva y una con deleted_at,
     * asi que reutilizar el numero de una version borrada choca contra el.
     *
     * Ademas es lo correcto conceptualmente: un numero de version no se
     * recicla, igual que no se recicla un numero de factura anulada.
     */
    public function nextVersionNumber(): int
    {
        return static::nextVersionFor($this->owner_type, (int) $this->owner_id);
    }

    public static function nextVersionFor(RuleSetOwnerType|string $ownerType, int $ownerId): int
    {
        $tipo = $ownerType instanceof RuleSetOwnerType ? $ownerType->value : $ownerType;

        $max = static::query()
            ->withTrashed()
            ->where('owner_type', $tipo)
            ->where('owner_id', $ownerId)
            ->max('version');

        return (int) $max + 1;
    }

    /**
     * Version publicada vigente para un dueno, o null si no hay ninguna.
     */
    public static function vigenteFor(RuleSetOwnerType $ownerType, int $ownerId): ?self
    {
        return static::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->published()
            ->orderByDesc('version')
            ->first();
    }
}
