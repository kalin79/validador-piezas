<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerdictStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verdict extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => VerdictStatus::class,
            'score' => 'float',
            'category_breakdown' => 'array',
            'scoring_formula_snapshot' => 'array',
        ];
    }

    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(ValidationRun::class);
    }

    public function isRejected(): bool
    {
        return $this->status === VerdictStatus::Rejected;
    }
}
