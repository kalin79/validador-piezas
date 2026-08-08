<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use BelongsToBrand;
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'metadata' => 'array',
            'indexed_at' => 'datetime',
        ];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function sourceRule(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'source_rule_id');
    }

    public function needsReindex(string $currentModel): bool
    {
        return $this->embedding === null || $this->embedding_model !== $currentModel;
    }
}
