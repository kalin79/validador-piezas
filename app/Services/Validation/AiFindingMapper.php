<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\FindingOrigin;
use App\Enums\RuleOutcome;
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
     * @return array{findings: array<int, FindingDraft>, discarded: array<int, string>, coverage: array<string, array{outcome: RuleOutcome, reason: string|null}>}
     */
    public function map(array $data, ResolvedRuleSet $resolved): array
    {
        // Solo reglas de juicio: son las unicas que se le mostraron al modelo.
        // Un hallazgo sobre una regla determinista contradiria una medicion
        // hecha por codigo con una opinion.
        $porCodigo = $resolved->judgmentRules()->keyBy(fn (Rule $r): string => $r->code);
        $todas = $resolved->rules->keyBy(fn (Rule $r): string => $r->code);

        $findings = [];
        $descartados = [];
        $conHallazgo = [];
        $dudosasBloqueadas = [];

        foreach ($data['findings'] ?? [] as $bruto) {
            if (! is_array($bruto)) {
                $descartados[] = 'Elemento de findings con forma invalida';

                continue;
            }

            $codigo = (string) ($bruto['rule_code'] ?? '');
            $regla = $porCodigo->get($codigo);

            // 1. El modelo puede inventar codigos, o citar una regla por
            //    codigo. Ninguna de las dos cosas se puede defender.
            if ($regla === null) {
                $descartados[] = $todas->has($codigo)
                    ? "Regla determinista citada por el modelo: {$codigo}"
                    : "Regla inexistente: {$codigo}";

                continue;
            }

            // 2. Confianza fuera de [0,1] no se reinterpreta: un 85 puede ser
            //    un porcentaje o un error, y adivinarlo es justo lo que no se
            //    puede hacer.
            $confianza = $this->confianza($bruto['confidence'] ?? null);

            if ($confianza === null) {
                $descartados[] = sprintf('%s descartado: confianza ausente o fuera de 0-1', $codigo);
                $dudosasBloqueadas[$codigo] = 'El modelo reporto un hallazgo con una confianza invalida.';

                continue;
            }

            if ($confianza < $this->minConfidence) {
                $descartados[] = sprintf('%s descartado por confianza %.2f', $codigo, $confianza);
                // El modelo sospecha algo pero no lo sostiene: la regla no se
                // puede dar por cumplida.
                $dudosasBloqueadas[$codigo] = sprintf('El modelo sospecho un incumplimiento con confianza %.2f, insuficiente para afirmarlo o descartarlo.', $confianza);

                continue;
            }

            $descripcion = trim((string) ($bruto['description'] ?? ''));

            if ($descripcion === '') {
                $descartados[] = "{$codigo} descartado: hallazgo sin descripcion";
                $dudosasBloqueadas[$codigo] = 'El modelo reporto un hallazgo sin describirlo.';

                continue;
            }

            // 3. La severidad la manda la regla, no el modelo.
            $severidad = $this->severidad($bruto, $regla, $confianza);
            $conHallazgo[$codigo] = true;

            $findings[] = new FindingDraft(
                category: $regla->category,
                severity: $severidad,
                description: $descripcion,
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

        return [
            'findings' => $findings,
            'discarded' => $descartados,
            'coverage' => $this->cobertura($data, $porCodigo, $conHallazgo, $dudosasBloqueadas),
        ];
    }

    /**
     * Estado de cada regla de juicio segun lo que el modelo declaro.
     *
     * Una lista de hallazgos vacia no distingue "cumple" de "no lo mire" ni de
     * "no se lee". Por eso se exige un pronunciamiento explicito por regla
     * (rule_assessments). Sin el, la regla no se da por cumplida.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<string, Rule>  $reglas
     * @param  array<string, bool>  $conHallazgo
     * @param  array<string, string>  $dudosas
     * @return array<string, array{outcome: RuleOutcome, reason: string|null}>
     */
    private function cobertura(array $data, Collection $reglas, array $conHallazgo, array $dudosas): array
    {
        $declaraciones = [];

        foreach (is_array($data['rule_assessments'] ?? null) ? $data['rule_assessments'] : [] as $a) {
            if (is_array($a) && isset($a['rule_code'])) {
                $declaraciones[(string) $a['rule_code']] = $a;
            }
        }

        $resultado = [];

        foreach ($reglas as $codigo => $regla) {
            // Un hallazgo valido es evidencia suficiente: la regla se evaluo y
            // se incumple, diga lo que diga la declaracion.
            if (isset($conHallazgo[$codigo])) {
                $resultado[$codigo] = ['outcome' => RuleOutcome::Evaluated, 'reason' => null];

                continue;
            }

            if (isset($dudosas[$codigo])) {
                $resultado[$codigo] = ['outcome' => RuleOutcome::NotDeterminable, 'reason' => $dudosas[$codigo]];

                continue;
            }

            $a = $declaraciones[$codigo] ?? null;

            if ($a === null) {
                $resultado[$codigo] = [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => 'El modelo no se pronuncio sobre esta regla.',
                ];

                continue;
            }

            $estado = (string) ($a['status'] ?? '');
            $confianza = $this->confianza($a['confidence'] ?? null);
            $motivo = $this->limpiar($a['evidence'] ?? null);

            $resultado[$codigo] = match (true) {
                $estado === 'no_determinable' => [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => 'El modelo declaro que no puede determinarlo'.($motivo ? ': '.$motivo : '.'),
                ],
                // Dice que incumple pero no registro el hallazgo: incoherente.
                // No se inventa el hallazgo ni se da por cumplida.
                $estado === 'incumple' => [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => 'El modelo marco la regla como incumplida sin registrar el hallazgo.',
                ],
                $estado === 'cumple' && ($confianza === null || $confianza < $this->doubtThreshold) => [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => sprintf('El modelo la considera cumplida con confianza %s, insuficiente para afirmarlo.', $confianza === null ? 'invalida' : number_format($confianza, 2)),
                ],
                $estado === 'cumple' => ['outcome' => RuleOutcome::Evaluated, 'reason' => null],
                default => [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => "Estado desconocido en la declaracion del modelo: '{$estado}'.",
                ],
            };
        }

        return $resultado;
    }

    private function confianza(mixed $valor): ?float
    {
        if (! is_numeric($valor)) {
            return null;
        }

        $n = (float) $valor;

        return $n < 0.0 || $n > 1.0 ? null : $n;
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

            // En el resto, un hallazgo dudoso baja un nivel y nunca bloquea.
            // Nunca sube: antes una regla Informativa dudosa terminaba Menor.
            return match ($deLaRegla) {
                Severity::Blocking => Severity::Major,
                Severity::Major => Severity::Minor,
                default => $deLaRegla,
            };
        }

        // En una regla no anulable el modelo no decide la severidad: si la
        // incumple con confianza, pesa lo que la regla dice. Antes podia
        // devolverla como info y la pieza se aprobaba.
        if ($regla->is_locked) {
            return $deLaRegla;
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
