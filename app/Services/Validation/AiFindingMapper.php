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
        private float $minorComplianceThreshold = 0.5,
        // Decision de negocio (25/09/2026), alineada con la escala del prompt:
        // de 0.7 en adelante es "evidencia solida".
        private float $criticalThreshold = 0.7,
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
        $sospechasDebiles = [];
        $declaraciones = $this->declaraciones($data);

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
                // Por debajo de 0.4 el propio modelo no lo sostiene (ver la
                // escala en AiEvaluator). Se guarda como sospecha debil: solo
                // deja la regla pendiente si NO hay una declaracion firme de
                // "cumple" o "no aplica" que alcance su umbral.
                $sospechasDebiles[$codigo] = sprintf('El modelo sospecho un incumplimiento con confianza %.2f, insuficiente para afirmarlo o descartarlo.', $confianza);

                continue;
            }

            // Reglas criticas (bloqueantes, mayores, no anulables): un
            // incumplimiento se afirma solo con evidencia solida (>= 0.7). Por
            // debajo, "otra persona podria concluir distinto": no se rechaza
            // ni se aprueba, decide una persona.
            if ($this->esCritica($regla) && $confianza < $this->criticalThreshold) {
                $descartados[] = sprintf('%s: hallazgo con confianza %.2f en regla critica, va a revision humana', $codigo, $confianza);
                $dudosasBloqueadas[$codigo] = sprintf(
                    'El modelo reporto un incumplimiento con confianza %.2f; en una regla %s se exige %.2f para afirmarlo.',
                    $confianza,
                    $regla->is_locked ? 'no anulable' : $regla->severity->label(),
                    $this->criticalThreshold,
                );

                continue;
            }

            // Incoherencia: el modelo registra un incumplimiento pero en su
            // pronunciamiento dice que no puede determinarlo o que la regla no
            // aplica. Un "no se" no puede restar puntos como si fuera un
            // incumplimiento: se descarta el hallazgo y la regla queda para
            // revision humana.
            $estadoDeclarado = (string) ($declaraciones[$codigo]['status'] ?? '');

            if (in_array($estadoDeclarado, ['no_determinable', 'no_aplica'], true)) {
                $descartados[] = sprintf('%s descartado: el modelo lo registro como hallazgo pero declaro "%s"', $codigo, $estadoDeclarado);
                $dudosasBloqueadas[$codigo] = $estadoDeclarado === 'no_determinable'
                    ? 'El modelo declaro que no puede determinarlo, aunque registro un hallazgo: no se cuenta como incumplimiento.'
                    : 'El modelo declaro que la regla no aplica, pero registro un hallazgo: respuesta incoherente.';

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
            'coverage' => $this->cobertura($data, $porCodigo, $conHallazgo, $dudosasBloqueadas, $sospechasDebiles),
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
    private function cobertura(array $data, Collection $reglas, array $conHallazgo, array $dudosas, array $sospechasDebiles = []): array
    {
        $declaraciones = $this->declaraciones($data);
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
                    'reason' => $sospechasDebiles[$codigo] ?? 'El modelo no se pronuncio sobre esta regla.',
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
                // "No aplica" es una conclusion, no una duda: la condicion de la
                // regla no se da en la pieza (por ejemplo, exige indicar el
                // precio y la pieza no menciona precio). Exige explicar por que.
                $estado === 'no_aplica' && $motivo !== null => [
                    'outcome' => RuleOutcome::Evaluated,
                    'reason' => 'No aplica: '.$motivo,
                ],
                $estado === 'no_aplica' => [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => 'El modelo dijo que la regla no aplica sin explicar por que.',
                ],
                $estado === 'cumple' && ($confianza === null || $confianza < $this->umbralCumple($regla)) => [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => sprintf(
                        'El modelo la considera cumplida con confianza %s; para una regla %s se exige %.2f.',
                        $confianza === null ? 'invalida' : number_format($confianza, 2),
                        $regla->is_locked ? 'no anulable' : $regla->severity->label(),
                        $this->umbralCumple($regla),
                    ),
                ],
                $estado === 'cumple' => ['outcome' => RuleOutcome::Evaluated, 'reason' => null],
                default => [
                    'outcome' => RuleOutcome::NotDeterminable,
                    'reason' => "Estado desconocido en la declaracion del modelo: '{$estado}'.",
                ],
            };

            // Una sospecha debil solo pesa si no hubo una conclusion firme.
            if (isset($sospechasDebiles[$codigo]) && $resultado[$codigo]['outcome'] !== RuleOutcome::Evaluated) {
                $resultado[$codigo] = ['outcome' => RuleOutcome::NotDeterminable, 'reason' => $sospechasDebiles[$codigo]];
            }
        }

        return $resultado;
    }

    /**
     * Confianza minima para aceptar un "cumple", segun lo que esta en juego.
     *
     * Decision de negocio (25/09/2026): en reglas menores o informativas, un
     * "cumple" dudoso cuesta poco si esta equivocado; en bloqueantes, mayores y
     * no anulables, dar por cumplida una regla que no lo esta puede publicar
     * algo que expone al cliente. Por eso el umbral es distinto.
     */
    private function umbralCumple(Rule $regla): float
    {
        return $this->esCritica($regla) ? $this->criticalThreshold : $this->minorComplianceThreshold;
    }

    /**
     * Bloqueantes, mayores y no anulables: donde equivocarse cuesta caro en
     * cualquiera de las dos direcciones (rechazar de mas o publicar de mas).
     */
    private function esCritica(Rule $regla): bool
    {
        return $regla->is_locked || in_array($regla->severity, [Severity::Blocking, Severity::Major], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function declaraciones(array $data): array
    {
        $declaraciones = [];

        foreach (is_array($data['rule_assessments'] ?? null) ? $data['rule_assessments'] : [] as $a) {
            if (is_array($a) && isset($a['rule_code'])) {
                $declaraciones[(string) $a['rule_code']] = $a;
            }
        }

        return $declaraciones;
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
