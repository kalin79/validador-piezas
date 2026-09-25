<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ValidationStatus;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ValidationRun extends Model
{
    use BelongsToBrand;
    use HasFactory;
    use HasUlids;
    use Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ValidationStatus::class,
            'resolved_rules_snapshot' => 'array',
            'deterministic_results' => 'array',
            'cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    /**
     * La ejecucion nace en 'pending' y transiciona a 'completed'. Todo lo demas
     * (que reglas se aplicaron, con que prompt, sobre que pieza) queda congelado.
     *
     * @return array<int, string>
     */
    /**
     * Estados terminales. Una ejecucion completada o fallida no cambia de
     * estado: sin esta guarda, una fallida podia pasar a completada (y una
     * completada a fallida) con un simple update.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $run): void {
            if (! $run->isDirty('status')) {
                return;
            }

            $original = $run->getOriginal('status');
            $original = $original instanceof ValidationStatus ? $original : ValidationStatus::tryFrom((string) $original);

            if (in_array($original, [ValidationStatus::Completed, ValidationStatus::Failed], true)) {
                throw new \App\Exceptions\ImmutableRecordException(sprintf(
                    'La ejecucion %s ya termino (%s) y su estado no puede cambiar.',
                    $run->public_id,
                    $original->value,
                ));
            }
        });
    }

    protected function mutableAttributes(): array
    {
        return [
            'status', 'deterministic_results', 'error_message',
            'started_at', 'finished_at', 'updated_at',
        ];
    }

    /**
     * Campos que se conocen recien al terminar de evaluar y que despues quedan
     * congelados: con que plantilla de prompt se construyo la peticion, que
     * modelo respondio, cuantos tokens costo y cual fue la respuesta cruda.
     *
     * Se crean nulos porque la ejecucion se registra ANTES de evaluar, para que
     * un fallo a mitad de camino deje rastro. Admiten una escritura y ninguna
     * mas.
     *
     * @return array<int, string>
     */
    protected function writeOnceAttributes(): array
    {
        return [
            'prompt_template_id', 'model_identifier',
            'input_tokens', 'output_tokens', 'cost_usd', 'raw_model_response',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function clientRuleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class, 'client_rule_set_id');
    }

    public function brandRuleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class, 'brand_rule_set_id');
    }

    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function verdict(): HasOne
    {
        return $this->hasOne(Verdict::class);
    }

    public function humanReviews(): HasMany
    {
        return $this->hasMany(HumanReview::class)->latest();
    }

    public function durationSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (float) $this->finished_at->diffInMilliseconds($this->started_at) / 1000;
    }
}
