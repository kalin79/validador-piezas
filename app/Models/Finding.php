<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FindingOrigin;
use App\Enums\FindingReviewState;
use App\Enums\RuleCategory;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Finding extends Model
{
    use HasFactory;
    use Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => RuleCategory::class,
            'severity' => Severity::class,
            'origin' => FindingOrigin::class,
            'review_state' => FindingReviewState::class,
            'evidence_data' => 'array',
            'confidence' => 'float',
        ];
    }

    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(ValidationRun::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('severity', Severity::Blocking->value);
    }

    public function isBlocking(): bool
    {
        return $this->severity === Severity::Blocking;
    }

    /**
     * Evidencia de auditoria: solo cambia el resultado de la revision humana.
     *
     * @return array<int, string>
     */
    protected function mutableAttributes(): array
    {
        return ['review_state', 'updated_at'];
    }
}
