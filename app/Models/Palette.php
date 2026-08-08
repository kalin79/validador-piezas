<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Palette extends Model
{
    use BelongsToBrand;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_delta_e_tolerance' => 'float', 'is_active' => 'boolean'];
    }

    public function colors(): HasMany
    {
        return $this->hasMany(PaletteColor::class)->orderBy('sort_order');
    }

    public function allowedColors(): HasMany
    {
        return $this->colors()->where('is_forbidden', false);
    }

    public function forbiddenColors(): HasMany
    {
        return $this->colors()->where('is_forbidden', true);
    }
}
