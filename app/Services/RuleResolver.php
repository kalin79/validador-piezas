<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OverrideAction;
use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Models\Brand;
use App\Models\Rule;
use App\Models\RuleSet;
use Illuminate\Support\Collection;

/**
 * Resuelve que reglas aplican a una pieza de una marca dada.
 *
 * Precedencia: la marca gana sobre el cliente, salvo que la regla del cliente
 * este marcada como is_locked (reglas normativas que ninguna marca puede anular).
 */
final class RuleResolver
{
    public function resolve(Brand $brand, ?string $channel = null): ResolvedRuleSet
    {
        $clientRuleSet = $this->publishedRuleSetFor(RuleSetOwnerType::Client, $brand->client_id);
        $brandRuleSet = $this->publishedRuleSetFor(RuleSetOwnerType::Brand, $brand->id);

        /** @var Collection<string, array{rule: Rule, origin: string, locked: bool}> $effective */
        $effective = collect();

        foreach ($this->activeRulesOf($clientRuleSet) as $rule) {
            $effective->put($rule->code, [
                'rule' => $rule,
                'origin' => 'client',
                'locked' => (bool) $rule->is_locked,
            ]);
        }

        foreach ($brandRuleSet?->rules ?? collect() as $rule) {
            $targetCode = $rule->overrides_code ?? $rule->code;
            $existing = $effective->get($targetCode);

            // Una regla corporativa bloqueada no puede anularse desde la marca.
            if ($existing !== null && $existing['locked']) {
                continue;
            }

            if ($rule->override_action === OverrideAction::Disable) {
                $effective->forget($targetCode);

                continue;
            }

            if (! $rule->is_active) {
                continue;
            }

            $effective->put($targetCode, [
                'rule' => $rule,
                'origin' => $existing !== null ? 'brand_override' : 'brand',
                'locked' => false,
            ]);
        }

        $effective = $effective->filter(
            static fn (array $entry): bool => $entry['rule']->appliesToChannel($channel)
        )->sortBy(static fn (array $entry, string $code): string => $code);

        $snapshot = $this->buildSnapshot($effective, $channel);

        return new ResolvedRuleSet(
            clientRuleSet: $clientRuleSet,
            brandRuleSet: $brandRuleSet,
            rules: $effective->map(static fn (array $entry): Rule => $entry['rule'])->values(),
            snapshot: $snapshot,
            hash: hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
        );
    }

    private function publishedRuleSetFor(RuleSetOwnerType $ownerType, int $ownerId): ?RuleSet
    {
        return RuleSet::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->where('status', RuleSetStatus::Published->value)
            ->orderByDesc('version')
            ->with('rules')
            ->first();
    }

    /** @return Collection<int, Rule> */
    private function activeRulesOf(?RuleSet $ruleSet): Collection
    {
        return ($ruleSet?->rules ?? collect())->filter(
            static fn (Rule $rule): bool => (bool) $rule->is_active
        );
    }

    /**
     * @param  Collection<string, array{rule: Rule, origin: string, locked: bool}>  $effective
     * @return array<int, array<string, mixed>>
     */
    private function buildSnapshot(Collection $effective, ?string $channel): array
    {
        return $effective->map(static fn (array $entry): array => [
            'code' => $entry['rule']->code,
            'origin' => $entry['origin'],
            'locked' => $entry['locked'],
            'rule_set_id' => $entry['rule']->rule_set_id,
            'rule_id' => $entry['rule']->id,
            'category' => $entry['rule']->category->value,
            'type' => $entry['rule']->type->value,
            'severity' => $entry['rule']->severity->value,
            'title' => $entry['rule']->title,
            'statement' => $entry['rule']->statement,
            'parameters' => $entry['rule']->parameters,
        ])->values()->all();
    }
}
