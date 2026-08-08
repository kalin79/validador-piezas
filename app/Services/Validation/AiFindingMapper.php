<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\FindingOrigin;
use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Models\Rule;
use App\Services\ResolvedRuleSet;
use Illuminate\Support\Collection;

/**
 * Convierte la salida del modelo en hallazgos persistibles.
 *
 * Aqui vive la desconfianza sana hacia el modelo: se descartan los hallazgos
 * que citan reglas inexistentes, se acota la severidad a la que la regla
 * declara, y los de baja confianza se degradan en vez de bloquear.
 *
 * Con una excepcion: las reglas marcadas como no anulables conservan su
 * severidad aunque el modelo dude. Ver el metodo severidad().
 */
final class AiFindingMapper
{
    public function __construct(
        private float $minConfidence = 0.4,
        private float $doubtThreshold = 0.6,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{findings: array<int, FindingDraft>, discarded: array<int, string>}
     */
    public function map(array $data, ResolvedRuleSet $resolved): array
    {
        $porCodigo = $resolved->rules->keyBy(fn (Rule $r): string => $r->code);

        $findings = [];
        $descartados = [];

        foreach ($data['findings'] ?? [] as $bruto) {
            $codigo = (string) ($bruto['rule_code'] ?? '');
            $confianza = (float) ($bruto['confidence'] ?? 0);

            // 1. El modelo puede inventar codigos. Un hallazgo que cita una
            //    regla inexistente no se puede defender ante nadie.
            $regla = $porCodigo->get($codigo);

            if ($regla === null) {
                $descartados[] = "Regla inexistente: {$codigo}";

                continue;
            }

            if ($confianza < $this->minConfidence) {
                $descartados[] = sprintf('%s descartado por confianza %.2f', $codigo, $confianza);

                continue;
            }

            // 2. La severidad la manda la regla, no el modelo. Si la regla es
            //    Menor, el modelo no puede elevarla a Bloqueante.
            $severidad = $this->severidad($bruto, $regla, $confianza);

            $findings[] = new FindingDraft(
                category: $regla->category,
                severity: $severidad,
                description: trim((string) ($bruto['description'] ?? 'Sin descripcion.')),
                ruleCode: $regla->code,
                ruleId: $regla->id,
                evidence: $this->limpiar($bruto['evidence'] ?? null),
                evidenceData: array_filter([
                    'model_severity' => $bruto['severity'] ?? null,
                    'rule_severity' => $regla->severity->value,
                    'confidence' => round($confianza, 3),
                    'doubtful' => $confianza < $this->doubtThreshold,
                    // Sin esto la pantalla muestra "1 hallazgo mayor" sobre una
                    // regla marcada como bloqueante y parece un error del
                    // sistema. Se deja el rastro para poder explicarlo.
                    'downgraded_from' => $severidad !== $regla->severity
                        ? $regla->severity->value
                        : null,
                ], static fn ($v): bool => $v !== null),
                suggestion: $this->limpiar($bruto['suggestion'] ?? null),
                origin: FindingOrigin::Ai,
            );
        }

        return ['findings' => $findings, 'discarded' => $descartados];
    }

    /**
     * @param  array<string, mixed>  $bruto
     */
    private function severidad(array $bruto, Rule $regla, float $confianza): Severity
    {
        $delModelo = Severity::tryFrom((string) ($bruto['severity'] ?? ''));
        $deLaRegla = $regla->severity;

        if ($confianza < $this->doubtThreshold) {
            /*
             * Las reglas no anulables son las normativas: el cliente decidio
             * que ninguna marca puede desactivarlas. Degradarlas porque el
             * modelo dudo contradice esa decision, y el calculo de riesgo se
             * invierte.
             *
             * En una regla de estilo, rechazar una pieza correcta cuesta mas
             * que dejar pasar una observacion. En una regla normativa es al
             * reves: el rechazo lo revierte una persona en la pantalla de
             * revision, pero publicar algo que expone al cliente ante el
             * regulador no se revierte.
             *
             * El hallazgo queda marcado como dudoso igual, para que la
             * revision humana sepa que hay que confirmarlo.
             */
            if ($regla->is_locked) {
                return $deLaRegla;
            }

            // En el resto, un hallazgo dudoso nunca bloquea.
            return $deLaRegla === Severity::Blocking ? Severity::Major : Severity::Minor;
        }

        if ($delModelo === null) {
            return $deLaRegla;
        }

        // El modelo puede bajar la severidad, nunca subirla por encima de la
        // que la regla declara.
        return $delModelo->defaultWeight() < $deLaRegla->defaultWeight()
            ? $delModelo
            : $deLaRegla;
    }

    private function limpiar(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }
}
