<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RuleSet;
use Illuminate\Support\Collection;

/**
 * Resultado de fusionar el conjunto corporativo del cliente con el de la marca.
 *
 * El snapshot y el hash son lo que se persiste en validation_runs: sin ellos,
 * un cambio futuro en el algoritmo de fusion volveria ilegibles los veredictos
 * historicos, aunque los conjuntos de reglas sigan intactos.
 */
final readonly class ResolvedRuleSet
{
    /**
     * @param  Collection<int, \App\Models\Rule>  $rules
     * @param  array<int, array<string, mixed>>  $snapshot
     */
    public function __construct(
        public ?RuleSet $clientRuleSet,
        public ?RuleSet $brandRuleSet,
        public Collection $rules,
        public array $snapshot,
        public string $hash,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rules->isEmpty();
    }

    /** @return Collection<int, \App\Models\Rule> */
    public function deterministicRules(): Collection
    {
        return $this->rules->filter(
            static fn ($rule): bool => $rule->type->value === 'deterministic'
        )->values();
    }

    /** @return Collection<int, \App\Models\Rule> */
    public function judgmentRules(): Collection
    {
        return $this->rules->filter(
            static fn ($rule): bool => $rule->type->value === 'judgment'
        )->values();
    }
}
