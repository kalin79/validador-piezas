<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * Mudar una marca de cliente cambia quien la ve y que reglas hereda.
     */
    protected static function booted(): void
    {
        static::updated(static function (self $brand): void {
            if ($brand->wasChanged('client_id')) {
                app(\App\Services\AuditLogger::class)->log('brand.moved', $brand,
                    oldValues: ['client_id' => $brand->getOriginal('client_id')],
                    newValues: ['client_id' => $brand->client_id],
                );
            }
        });
    }

    /**
     * Una marca casi nunca se muestra ni se evalua sin su cliente: fullName()
     * lo necesita para la etiqueta y setting() para resolver la herencia de
     * configuracion. Cargarlo siempre evita N+1 y las violaciones de lazy
     * loading que Model::shouldBeStrict() convierte en excepcion.
     *
     * @var array<int, string>
     */
    protected $with = ['client'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function palettes(): HasMany
    {
        return $this->hasMany(Palette::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function ruleSets(): HasMany
    {
        return $this->hasMany(RuleSet::class, 'owner_id')->where('owner_type', 'brand');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_brand_access')->using(\App\Models\Pivots\AccesoDeEquipo::class)->withTimestamps();
    }

    /**
     * Configuracion efectiva: lo de la marca sobrescribe lo del cliente.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key)
            ?? data_get($this->clientSettings(), $key)
            ?? $default;
    }

    public function fullName(): string
    {
        return sprintf('%s / %s', $this->clientName(), $this->name);
    }

    /**
     * Nombre del cliente sin provocar lazy loading si la relacion no vino cargada.
     */
    public function clientName(): string
    {
        if ($this->relationLoaded('client')) {
            return $this->getRelation('client')?->name ?? '—';
        }

        return Client::query()->whereKey($this->client_id)->value('name') ?? '—';
    }

    /** @return array<string, mixed>|null */
    private function clientSettings(): ?array
    {
        if ($this->relationLoaded('client')) {
            return $this->getRelation('client')?->settings;
        }

        return Client::query()->whereKey($this->client_id)->value('settings');
    }
}
