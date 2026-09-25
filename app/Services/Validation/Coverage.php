<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\RuleOutcome;

/**
 * Registro de que se evaluo realmente en una ejecucion, regla por regla.
 *
 * Es la base del principio "sin evidencia no hay cumple": el veredicto solo
 * puede ser una aprobacion si todas las reglas aplicables quedaron en
 * Evaluated. La ausencia de registro cuenta como NotEvaluated, nunca como
 * cumplimiento.
 */
final class Coverage
{
    /** @var array<string, array{outcome: RuleOutcome, reason: string|null, engine: string}> */
    private array $porRegla = [];

    public function mark(string $code, RuleOutcome $outcome, string $engine, ?string $reason = null): void
    {
        if ($code === '') {
            return;
        }

        $actual = $this->porRegla[$code] ?? null;

        // Gana el peor resultado: una regla que un motor evaluo y otro no pudo
        // evaluar no esta evaluada del todo.
        if ($actual !== null && $actual['outcome']->gravedad() >= $outcome->gravedad()) {
            return;
        }

        $this->porRegla[$code] = ['outcome' => $outcome, 'reason' => $reason, 'engine' => $engine];
    }

    public function outcomeOf(string $code): RuleOutcome
    {
        return $this->porRegla[$code]['outcome'] ?? RuleOutcome::NotEvaluated;
    }

    public function has(string $code): bool
    {
        return isset($this->porRegla[$code]);
    }

    /**
     * Reglas aplicables que NO quedaron evaluadas, con su motivo.
     *
     * @param  iterable<string>  $codigosAplicables
     * @return array<string, array{outcome: string, reason: string|null}>
     */
    public function pending(iterable $codigosAplicables): array
    {
        $pendientes = [];

        foreach ($codigosAplicables as $code) {
            $registro = $this->porRegla[$code] ?? null;

            if ($registro === null) {
                $pendientes[$code] = [
                    'outcome' => RuleOutcome::NotEvaluated->value,
                    'reason' => 'Ningun motor evaluo esta regla.',
                ];

                continue;
            }

            if ($registro['outcome'] !== RuleOutcome::Evaluated) {
                $pendientes[$code] = [
                    'outcome' => $registro['outcome']->value,
                    'reason' => $registro['reason'],
                ];
            }
        }

        return $pendientes;
    }

    /**
     * Forma persistible en validation_runs.deterministic_results['coverage'].
     *
     * @return array<string, array{outcome: string, reason: string|null, engine: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (array $r): array => [
            'outcome' => $r['outcome']->value,
            'reason' => $r['reason'],
            'engine' => $r['engine'],
        ], $this->porRegla);
    }
}
