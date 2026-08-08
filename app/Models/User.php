<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens;   // <-- agregar    
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'active_brand_id',
        'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    public function activeBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'active_brand_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function hasGlobalAccess(): bool
    {
        return $this->hasAnyRole(['super_admin', 'auditor']);
    }

    /**
     * Marcas visibles para este usuario, derivadas de sus equipos.
     *
     * @return Collection<int, int>
     */
    public function accessibleBrandIds(): Collection
    {
        return collect($this->resolveAccessibleBrandIds());
    }

    /**
     * Se cachea un array de enteros, nunca un objeto.
     *
     * Cachear una Collection obliga al driver a serializar un objeto, y al
     * deserializarlo puede devolver __PHP_Incomplete_Class. Un array de ints
     * es seguro en cualquier driver: file, database, redis o array.
     *
     * @return array<int, int>
     */
    protected function resolveAccessibleBrandIds(): array
    {
        return cache()->remember(
            $this->accessCacheKey(),
            now()->addMinutes(10),
            function (): array {
                if ($this->hasGlobalAccess()) {
                    return Brand::query()->pluck('id')->map(intval(...))->all();
                }

                $teamIds = $this->teams()
                    ->where('teams.is_active', true)
                    ->pluck('teams.id');

                if ($teamIds->isEmpty()) {
                    return [];
                }

                $clientIds = DB::table('team_client_access')
                    ->whereIn('team_id', $teamIds)
                    ->pluck('client_id');

                $viaClient = $clientIds->isEmpty()
                    ? collect()
                    : Brand::query()->whereIn('client_id', $clientIds)->pluck('id');

                $direct = DB::table('team_brand_access')
                    ->whereIn('team_id', $teamIds)
                    ->pluck('brand_id');

                return $viaClient
                    ->merge($direct)
                    ->map(intval(...))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            }
        );
    }

    /** @return Collection<int, int> */
    public function accessibleClientIds(): Collection
    {
        $brandIds = $this->resolveAccessibleBrandIds();

        if ($brandIds === []) {
            return collect();
        }

        return Brand::query()
            ->whereIn('id', $brandIds)
            ->distinct()
            ->pluck('client_id')
            ->map(intval(...))
            ->values();
    }

    public function canAccessBrand(int $brandId): bool
    {
        return in_array($brandId, $this->resolveAccessibleBrandIds(), true);
    }

    public function accessibleBrandCount(): int
    {
        return count($this->resolveAccessibleBrandIds());
    }

    /**
     * Cambia el contexto de trabajo. Revalida el acceso: no confia en el desplegable.
     */
    public function switchToBrand(int $brandId): bool
    {
        if (!$this->canAccessBrand($brandId)) {
            return false;
        }

        $this->forceFill(['active_brand_id' => $brandId])->save();

        return true;
    }

    public function forgetAccessCache(): void
    {
        cache()->forget($this->accessCacheKey());
    }

    private function accessCacheKey(): string
    {
        return "user.{$this->id}.accessible_brands";
    }
}