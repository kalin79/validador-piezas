<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function ruleSets(): HasMany
    {
        return $this->hasMany(RuleSet::class);
    }

    public function corporateRuleSets(): HasMany
    {
        return $this->hasMany(RuleSet::class, 'owner_id')->where('owner_type', 'client');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
