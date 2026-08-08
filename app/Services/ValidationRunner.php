<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ValidationStatus;
use App\Models\Asset;
use App\Models\ValidationRun;
use App\Services\Validation\AiEvaluator;
use App\Services\Validation\DeterministicEngine;
use App\Services\Validation\VerdictCalculator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ejecuta una validacion completa y persiste la evidencia.
 *
 * Orden de trabajo:
 *
 * 1. Se resuelve el conjunto de reglas y se congela su snapshot.
 * 2. Se crea la ejecucion apuntando a ese snapshot, antes de evaluar nada.
 * 3. Corre el motor determinista, que es barato y exacto.
 * 4. Corre el evaluador de IA, que recibe los hallazgos anteriores como contexto.
 * 5. Se consolida el veredicto sobre el conjunto completo de hallazgos.
 *
 * Si algo falla a mitad de camino la ejecucion queda registrada en estado
 * fallido, apuntando a las reglas que se iban a aplicar. Ausencia de veredicto
 * nunca equivale a aprobacion.
 */
final class ValidationRunner
{
    public function __construct(
        private RuleResolver $resolver = new RuleResolver(),
        private DeterministicEngine $engine = new DeterministicEngine(),
        private VerdictCalculator $calculator = new VerdictCalculator(),
        private ?AiEvaluator $ai = null,
    ) {}

    public function run(Asset $asset, ?int $triggeredBy = null, bool $withAi = true, ?string $model = null): ValidationRun
    {
        $brand = $asset->brand;
        $channel = $asset->submission?->channel;

        $resolved = $this->resolver->resolve($brand, $channel);

        $run = ValidationRun::create([
            'asset_id' => $asset->id,
            'brand_id' => $asset->brand_id,
            'client_rule_set_id' => $resolved->clientRuleSet?->id,
            'brand_rule_set_id' => $resolved->brandRuleSet?->id,
            'resolved_rules_snapshot' => $resolved->snapshot,
            'resolution_hash' => $resolved->hash,
            'triggered_by' => $triggeredBy,
            'status' => ValidationStatus::Running->value,
            'started_at' => now(),
        ]);

        try {
            $determinista = $this->engine->run($asset, $resolved, $channel);
            $todos = $determinista['findings'];

            $meta = [
                'evaluated_rules' => $resolved->deterministicRules()->count(),
                'deterministic_findings' => count($determinista['findings']),
                'evaluator_errors' => $determinista['errors'],
                'ai_ran' => false,
            ];

            $actualizacion = [];

            // La capa de IA solo corre si hay reglas de juicio que evaluar.
            $corresponde = $withAi && $resolved->judgmentRules()->isNotEmpty();

            if ($corresponde) {
                try {
                    $ia = $this->ai()->evaluate($asset, $resolved, $channel, $determinista['findings'], $model);

                    $todos = array_merge($todos, $ia['findings']);
                    $respuesta = $ia['response'];

                    $meta['ai_ran'] = true;
                    $meta['ai_simulated'] = $respuesta->simulated;
                    $meta['ai_findings'] = count($ia['findings']);
                    // Se deja constancia de si el modelo lo eligio una persona
                    // o vino de la configuracion. En auditoria importa: un
                    // veredicto obtenido con un modelo elegido a mano no es
                    // comparable con el flujo normal sin decirlo.
                    $meta['model_requested'] = $model;
                    $meta['ai_discarded'] = $ia['discarded'];

                    $actualizacion = [
                        'prompt_template_id' => $ia['template']->id,
                        'model_identifier' => $respuesta->model,
                        'input_tokens' => $respuesta->inputTokens,
                        'output_tokens' => $respuesta->outputTokens,
                        'cost_usd' => $respuesta->costUsd,
                        'raw_model_response' => json_encode($respuesta->raw, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    ];

                    if (filled($ia['extracted_text'])) {
                        $asset->update(['extracted_text' => $ia['extracted_text']]);
                    }
                } catch (Throwable $e) {
                    // Un fallo de la IA no invalida el analisis determinista:
                    // se conserva lo medido y se deja constancia de lo que falto.
                    $meta['ai_error'] = $e->getMessage();
                }
            }

            $reglasAplicadas = $resolved->rules->count();

            DB::transaction(function () use ($run, $todos, $brand, $reglasAplicadas): void {
                foreach ($todos as $draft) {
                    $run->findings()->create($draft->toAttributes());
                }

                $run->verdict()->create(
                    $this->calculator->calculate(
                        $run->findings()->get(),
                        $brand,
                        $reglasAplicadas,
                    )
                );
            });

            $run->update(array_merge($actualizacion, [
                'status' => ValidationStatus::Completed->value,
                'finished_at' => now(),
                'deterministic_results' => $meta,
                'error_message' => $this->mensajeDeError($meta),
            ]));
        } catch (Throwable $e) {
            $run->update([
                'status' => ValidationStatus::Failed->value,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $run->refresh();
    }

    private function ai(): AiEvaluator
    {
        return $this->ai ??= new AiEvaluator();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function mensajeDeError(array $meta): ?string
    {
        $partes = array_filter([
            $meta['evaluator_errors'] === [] ? null : implode(' | ', $meta['evaluator_errors']),
            $meta['ai_error'] ?? null,
        ]);

        return $partes === [] ? null : implode(' | ', $partes);
    }
}
