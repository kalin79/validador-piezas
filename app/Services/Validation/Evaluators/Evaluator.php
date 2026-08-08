<?php

declare(strict_types=1);

namespace App\Services\Validation\Evaluators;

use App\Models\Asset;
use App\Models\Rule;
use App\Services\Validation\FindingDraft;
use Illuminate\Support\Collection;

interface Evaluator
{
    /**
     * @param  Collection<int, Rule>  $rules  reglas deterministas del conjunto efectivo
     * @return array<int, FindingDraft>
     */
    public function evaluate(Asset $asset, Collection $rules, ?string $channel): array;

    /**
     * Categorias de regla que este evaluador sabe atender.
     *
     * @return array<int, string>
     */
    public function handles(): array;
}
