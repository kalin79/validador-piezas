<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerdictStatus;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HumanReview extends Model
{
    use HasFactory;
    use Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'machine_verdict' => VerdictStatus::class,
            'final_verdict' => VerdictStatus::class,
            'overrode_machine' => 'boolean',
            'finding_decisions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $review): void {
            $review->overrode_machine = $review->machine_verdict !== $review->final_verdict;
        });
    }

    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(ValidationRun::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
