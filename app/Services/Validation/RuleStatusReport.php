<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\RuleOutcome;
use App\Enums\Severity;
use App\Models\Finding;
use App\Models\ValidationRun;
use Illuminate\Support\Collection;

/**
 * Estado de cada regla aplicada en una ejecucion: incumple, cumple o pendiente.
 *
 * Una sola fuente para la API, la validacion rapida y el modal de hallazgos.
 * Antes cada pantalla lo calculaba por su cuenta y las tres daban por cumplida
 * toda regla determinista sin hallazgo, se hubiera medido o no.
 *
 * Regla de oro: "cumple" solo se afirma si la cobertura registrada dice que la
 * regla se evaluo. Las ejecuciones anteriores a la cobertura no tienen esa
 * constancia, y por eso sus reglas sin hallazgo se reportan como pendientes.
 */
final class RuleStatusReport
{
    public const INCUMPLE = 'incumple';
    public const CUMPLE = 'cumple';
    public const PENDIENTE = 'pendiente';

    private const ORDEN = ['blocking' => 0, 'major' => 1, 'minor' => 2, 'info' => 3];

    private const ETIQUETAS_SEVERIDAD = [
        'blocking' => 'bloqueante',
        'major' => 'mayor',
        'minor' => 'menor',
        'info' => 'informativo',
    ];

    /**
     * @return array{
     *     rules: array<int, array{code: string, title: string, type: string, severity: string, origin: string, estado: string, detalle: string, severidad_hallada: string|null}>,
     *     resumen: array{incumple: int, cumple: int, pendiente: int},
     *     con_cobertura: bool
     * }
     */
    public static function for(?ValidationRun $run): array
    {
        $resultado = ['rules' => [], 'resumen' => [self::INCUMPLE => 0, self::CUMPLE => 0, self::PENDIENTE => 0], 'con_cobertura' => false];

        if ($run === null) {
            return $resultado;
        }

        $meta = (array) ($run->deterministic_results ?? []);
        $cobertura = is_array($meta['coverage'] ?? null) ? $meta['coverage'] : null;
        $resultado['con_cobertura'] = $cobertura !== null;

        /** @var Collection<int, Finding> $findings */
        $findings = $run->findings ?? collect();
        $porRegla = $findings->groupBy('rule_id');
        $porCodigo = $findings->groupBy('rule_code');

        foreach (($run->resolved_rules_snapshot ?? []) as $r) {
            $codigo = (string) ($r['code'] ?? '?');

            $deLaRegla = collect($porRegla[$r['rule_id'] ?? null] ?? []);

            if ($deLaRegla->isEmpty()) {
                $deLaRegla = collect($porCodigo[$codigo] ?? []);
            }

            // Los hallazgos informativos no son incumplimientos: suelen ser
            // avisos de "no se pudo evaluar". Se cuentan como incumplimiento
            // solo los que pesan.
            $reales = $deLaRegla->reject(fn (Finding $f): bool => $f->severity === Severity::Info);
            $severidadHallada = null;

            if ($reales->isNotEmpty()) {
                $peor = $reales->sortBy(fn (Finding $f): int => self::ORDEN[$f->severity->value] ?? 9)->first();
                $severidadHallada = $peor?->severity->value;
                $nombre = self::ETIQUETAS_SEVERIDAD[$severidadHallada] ?? $severidadHallada;
                $n = $reales->count();

                $estado = self::INCUMPLE;
                $detalle = $n === 1 ? '1 hallazgo '.$nombre : $n.' hallazgos, el mas grave '.$nombre;
            } elseif ($cobertura === null) {
                $estado = self::PENDIENTE;
                $detalle = 'ejecucion anterior al registro de cobertura: no hay constancia de que se haya verificado';
            } else {
                $registro = $cobertura[$codigo] ?? null;
                $outcome = RuleOutcome::tryFrom((string) ($registro['outcome'] ?? '')) ?? RuleOutcome::NotEvaluated;

                if ($outcome === RuleOutcome::Evaluated) {
                    $estado = self::CUMPLE;
                    $detalle = ($r['type'] ?? '') === 'deterministic'
                        ? 'medida por codigo, sin desviacion'
                        : 'juzgada por el modelo con evidencia, sin incumplimiento';
                } else {
                    $estado = self::PENDIENTE;
                    $detalle = strtolower($outcome->label()).': '.($registro['reason'] ?? 'ningun motor la evaluo');
                }
            }

            $resultado['resumen'][$estado]++;

            $resultado['rules'][] = [
                'code' => $codigo,
                'title' => (string) ($r['title'] ?? ''),
                'type' => (string) ($r['type'] ?? ''),
                'severity' => (string) ($r['severity'] ?? ''),
                'origin' => (string) ($r['origin'] ?? ''),
                'estado' => $estado,
                'detalle' => $detalle,
                'severidad_hallada' => $severidadHallada,
            ];
        }

        return $resultado;
    }

    /**
     * @return array<int, string>
     */
    public static function codes(array $reporte, string $estado): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['code'],
            array_filter($reporte['rules'], static fn (array $r): bool => $r['estado'] === $estado),
        ));
    }
}
