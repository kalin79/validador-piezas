<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OverrideAction;
use App\Enums\RuleCategory;
use App\Enums\RuleType;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rule extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => RuleCategory::class,
            'type' => RuleType::class,
            'severity' => Severity::class,
            'override_action' => OverrideAction::class,
            'parameters' => 'array',
            'positive_examples' => 'array',
            'negative_examples' => 'array',
            'applies_to_channels' => 'array',
            'is_locked' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function palette(): BelongsTo
    {
        return $this->belongsTo(Palette::class);
    }

    public function appliesToChannel(?string $channel): bool
    {
        if ($channel === null || blank($this->applies_to_channels)) {
            return true;
        }

        return in_array($channel, $this->applies_to_channels, true);
    }

    public function isDeterministic(): bool
    {
        return $this->type === RuleType::Deterministic;
    }
}
