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


    /**
     * Las reglas de un conjunto publicado o retirado no se editan ni se
     * borran: las validaciones historicas las citan. Antes solo lo impedia la
     * interfaz; un script o tinker podia cambiarlas.
     *
     * Crear reglas en un conjunto publicado sigue permitido para seeders, que
     * arman el conjunto y sus reglas en el mismo paso.
     */
    protected static function booted(): void
    {
        $guarda = static function (self $rule, string $accion): void {
            $estado = RuleSet::query()->whereKey($rule->getRawOriginal('rule_set_id') ?? $rule->rule_set_id)->value('status');
            $estado = $estado instanceof \App\Enums\RuleSetStatus ? $estado : \App\Enums\RuleSetStatus::tryFrom((string) $estado);

            if ($estado !== null && $estado !== \App\Enums\RuleSetStatus::Draft) {
                throw new \App\Exceptions\ImmutableRecordException(sprintf(
                    'La regla %s pertenece a un conjunto %s y no se puede %s. Crea una version nueva del conjunto.',
                    $rule->code,
                    $estado->label(),
                    $accion,
                ));
            }
        };

        static::updating(static fn (self $r) => $guarda($r, 'modificar'));
        static::deleting(static fn (self $r) => $guarda($r, 'eliminar'));
    }

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
