<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\RuleType;
use App\Models\Asset;
use App\Models\Rule;
use App\Services\Validation\Evaluators\ContrastEvaluator;
use App\Services\Validation\Evaluators\DuplicateEvaluator;
use App\Services\Validation\Evaluators\Evaluator;
use App\Services\Validation\Evaluators\FormatEvaluator;
use App\Services\Validation\Evaluators\PaletteEvaluator;
use App\Services\ResolvedRuleSet;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Orquesta los evaluadores deterministas.
 *
 * Todo lo que se puede decidir con codigo se decide aqui, antes de gastar una
 * sola llamada al modelo. Los hallazgos que produce se le pasan despues a la
 * IA como contexto, para que razone sobre ellos en vez de repetir el trabajo.
 */
final class DeterministicEngine
{
    /** @var array<int, Evaluator> */
    private array $evaluators;

    public function __construct(?array $evaluators = null)
    {
        $this->evaluators = $evaluators ?? [
            new DuplicateEvaluator(),
            new FormatEvaluator(),
            new PaletteEvaluator(),
            new ContrastEvaluator(),
        ];
    }

    /**
     * @return array{findings: array<int, FindingDraft>, errors: array<int, string>}
     */
    public function run(Asset $asset, ResolvedRuleSet $resolved, ?string $channel): array
    {
        $deterministas = $resolved->rules->filter(
            static fn (Rule $rule): bool => $rule->type === RuleType::Deterministic
        );

        $findings = [];
        $errors = [];

        foreach ($this->evaluators as $evaluator) {
            $aplicables = $this->rulesFor($evaluator, $deterministas);

            // Un evaluador sin categorias declaradas (como el de duplicados)
            // corre siempre: no depende de que exista una regla que lo pida.
            if ($evaluator->handles() !== [] && $aplicables->isEmpty()) {
                continue;
            }

            try {
                foreach ($evaluator->evaluate($asset, $aplicables, $channel) as $finding) {
                    $findings[] = $finding;
                }
            } catch (Throwable $e) {
                // Un evaluador que falla no puede tumbar la validacion completa,
                // pero tampoco puede desaparecer en silencio: se registra.
                $errors[] = sprintf('%s: %s', class_basename($evaluator), $e->getMessage());
            }
        }

        return ['findings' => $findings, 'errors' => $errors];
    }

    /**
     * Categorias que hoy tienen un evaluador determinista detras.
     *
     * Se calcula preguntandole a los evaluadores en vez de mantener una lista
     * aparte: una lista se desactualiza el dia que alguien agrega o quita un
     * evaluador, y el sintoma seria una regla que no se evalua sin que nadie
     * lo note.
     *
     * @return array<int, string>
     */
    public function supportedCategories(): array
    {
        return collect($this->evaluators)
            ->flatMap(static fn (Evaluator $e): array => $e->handles())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Rule>  $rules
     * @return Collection<int, Rule>
     */
    private function rulesFor(Evaluator $evaluator, Collection $rules): Collection
    {
        $categorias = $evaluator->handles();

        if ($categorias === []) {
            return collect();
        }

        return $rules->filter(
            static fn (Rule $rule): bool => in_array($rule->category->value, $categorias, true)
        )->values();
    }
}
