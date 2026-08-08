<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use BelongsToBrand;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'extracted_metadata' => 'array',
            'extracted_palette' => 'array',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function validationRuns(): HasMany
    {
        return $this->hasMany(ValidationRun::class)->latest();
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(ValidationRun::class)->latestOfMany();
    }

    /**
     * Otras piezas de la misma marca con el mismo contenido binario.
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'file_hash', 'file_hash')
            ->where('id', '!=', $this->id)
            ->where('brand_id', $this->brand_id);
    }
}
