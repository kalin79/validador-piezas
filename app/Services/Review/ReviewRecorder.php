<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Enums\FindingOrigin;
use App\Enums\FindingReviewState;
use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Enums\VerdictStatus;
use App\Models\Finding;
use App\Models\HumanReview;
use App\Models\ValidationRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra el juicio de una persona sobre una validacion.
 *
 * Es la pieza que convierte "creo que funciona" en un numero. Sin esto no hay
 * forma de saber si el motor mejora: solo la impresion de quien lo mira.
 *
 * Tres decisiones de diseno que conviene entender:
 *
 * 1. La revision NO cambia el veredicto de la maquina. El veredicto original
 *    queda intacto y la decision humana se guarda al lado. Reescribirlo
 *    borraria la evidencia de que la maquina se equivoco, que es justo el dato
 *    que hace falta para corregirla.
 *
 * 2. Un hallazgo que el revisor agrega se marca como agregado por humano y no
 *    se mezcla con los de la maquina. Al medir precision, contarlos juntos
 *    inflaria el resultado.
 *
 * 3. Una ejecucion se revisa una sola vez. Revisar dos veces la misma con
 *    criterios distintos haria las metricas imposibles de interpretar.
 */
final class ReviewRecorder
{
    /**
     * @param  array<int, string>  $decisiones   id del hallazgo => confirmed|false_positive
     * @param  array<int, array{severity: string, category: string, rule_code: string|null, description: string}>  $agregados
     */
    public function record(
        ValidationRun $run,
        int $reviewerId,
        VerdictStatus $veredictoFinal,
        array $decisiones,
        array $agregados = [],
        ?string $justificacion = null,
    ): HumanReview {
        if ($run->humanReviews()->exists()) {
            throw new RuntimeException('Esta validacion ya fue revisada.');
        }

        $veredictoMaquina = $run->verdict?->status;

        if ($veredictoMaquina === null) {
            throw new RuntimeException('No se puede revisar una validacion sin veredicto.');
        }

        // Si el revisor cambia el veredicto, tiene que decir por que. Una
        // anulacion sin motivo no sirve para corregir nada despues.
        if ($veredictoMaquina !== $veredictoFinal && blank($justificacion)) {
            throw new RuntimeException('Al cambiar el veredicto hay que justificarlo.');
        }

        return DB::transaction(function () use (
            $run, $reviewerId, $veredictoMaquina, $veredictoFinal, $decisiones, $agregados, $justificacion
        ): HumanReview {
            $registro = [];

            foreach ($run->findings as $finding) {
                $decision = $decisiones[$finding->id] ?? FindingReviewState::Confirmed->value;

                $estado = $decision === FindingReviewState::FalsePositive->value
                    ? FindingReviewState::FalsePositive
                    : FindingReviewState::Confirmed;

                $finding->update(['review_state' => $estado->value]);

                $registro[] = [
                    'finding_id' => $finding->id,
                    'rule_code' => $finding->rule_code,
                    'severity' => $finding->severity->value,
                    'origin' => $finding->origin->value,
                    'decision' => $estado->value,
                ];
            }

            foreach ($agregados as $nuevo) {
                if (blank($nuevo['description'] ?? null)) {
                    continue;
                }

                $creado = Finding::create([
                    'validation_run_id' => $run->id,
                    'rule_code' => $nuevo['rule_code'] ?: null,
                    'category' => RuleCategory::from($nuevo['category'])->value,
                    'severity' => Severity::from($nuevo['severity'])->value,
                    'origin' => FindingOrigin::Human->value,
                    'description' => $nuevo['description'],
                    'review_state' => FindingReviewState::AddedByHuman->value,
                ]);

                $registro[] = [
                    'finding_id' => $creado->id,
                    'rule_code' => $creado->rule_code,
                    'severity' => $creado->severity->value,
                    'origin' => FindingOrigin::Human->value,
                    'decision' => FindingReviewState::AddedByHuman->value,
                ];
            }

            return HumanReview::create([
                'validation_run_id' => $run->id,
                'reviewer_id' => $reviewerId,
                'machine_verdict' => $veredictoMaquina->value,
                'final_verdict' => $veredictoFinal->value,
                'justification' => $justificacion,
                'finding_decisions' => $registro,
            ]);
        });
    }
}
