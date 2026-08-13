<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\Severity;
use App\Enums\VerdictStatus;
use App\Models\Brand;
use App\Models\Finding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Consolida los hallazgos en un veredicto.
 *
 * Dos numeros que responden dos preguntas distintas:
 *
 *   ESTADO   -> puede publicarse? Un solo bloqueante fuerza rechazo, sin
 *               importar nada mas. No se negocia.
 *   PUNTAJE  -> que tan bien esta hecha? Mide calidad de ejecucion y por eso
 *               los bloqueantes NO lo penalizan.
 *
 * Antes el bloqueante pesaba 100 y agotaba el puntaje. Consecuencia: una
 * pieza rechazada por un superlativo y una rechazada por veinte problemas
 * daban lo mismo, cero, y el numero dejaba de informar justo donde mas
 * falta hace. Peor aun, "Rechazado 0.0/100" se lee como "la pieza esta
 * totalmente mal" cuando puede tener 27 de 31 reglas cumplidas.
 *
 * Con el peso en cero, esa misma pieza dice "Rechazado por normativa,
 * calidad 80": no puede publicarse, y ademas esta bien construida. Son dos
 * hechos ciertos a la vez.
 *
 * Si prefieres que un bloqueante tambien reste calidad, el peso es
 * configurable por marca en el ajuste scoring_weights.
 *
 * Los pesos se congelan en el veredicto. Si manana cambian, el puntaje de
 * ayer se sigue explicando con los pesos de ayer.
 */
final class VerdictCalculator
{
    private const DEFAULT_WEIGHTS = [
        'blocking' => 0.0,
        'major' => 15.0,
        'minor' => 5.0,
        'info' => 0.0,
    ];

    private const DEFAULT_OBSERVATION_THRESHOLD = 90.0;

    /**
     * @param  Collection<int, Finding>  $findings
     * @param  int|null  $rulesApplied  cuantas reglas se resolvieron para la pieza.
     *                                  Null conserva el comportamiento anterior.
     * @return array<string, mixed> atributos listos para crear el Verdict
     */
    public function calculate(
        Collection $findings,
        ?Brand $brand = null,
        ?int $rulesApplied = null,
    ): array {
        $personalizados = $this->pesosDeclarados($brand);
        $pesos = $this->weightsFor($personalizados);
        $umbral = (float) ($brand?->setting('observation_threshold') ?? self::DEFAULT_OBSERVATION_THRESHOLD);

        $bloqueantes = $findings->where('severity', Severity::Blocking)->count();
        $mayores = $findings->where('severity', Severity::Major)->count();
        $menores = $findings->where('severity', Severity::Minor)->count();

        $penalizacion = 0.0;

        foreach ($findings as $finding) {
            $penalizacion += $pesos[$finding->severity->value] ?? 0.0;
        }

        $puntaje = max(0.0, 100.0 - $penalizacion);

        // Cero reglas resueltas no es una pieza impecable: es una pieza que
        // nadie midio. Aprobarla con 100 puntos es el peor error posible en un
        // sistema de auditoria, porque construye confianza sobre nada. Se
        // distingue explicitamente.
        $estado = match (true) {
            $rulesApplied === 0 => VerdictStatus::NotEvaluated,
            $bloqueantes > 0 => VerdictStatus::Rejected,
            $puntaje < $umbral => VerdictStatus::ApprovedWithObservations,
            $findings->isNotEmpty() => VerdictStatus::ApprovedWithObservations,
            default => VerdictStatus::Approved,
        };

        // Sin reglas evaluadas el puntaje no significa nada. Se deja en cero en
        // vez de en cien para que ninguna pantalla ni metrica lo lea como una
        // pieza perfecta.
        if ($estado === VerdictStatus::NotEvaluated) {
            $puntaje = 0.0;
        }

        return [
            'status' => $estado->value,
            'score' => round($puntaje, 2),
            'blocking_count' => $bloqueantes,
            'major_count' => $mayores,
            'minor_count' => $menores,
            'category_breakdown' => $findings
                ->groupBy(fn (Finding $f): string => $f->category->value)
                ->map(fn (Collection $g): array => [
                    'total' => $g->count(),
                    'blocking' => $g->where('severity', Severity::Blocking)->count(),
                    'major' => $g->where('severity', Severity::Major)->count(),
                    'minor' => $g->where('severity', Severity::Minor)->count(),
                    'info' => $g->where('severity', Severity::Info)->count(),
                ])
                ->all(),
            'scoring_formula_snapshot' => [
                'weights' => $pesos,
                // Sin esto no se puede distinguir un veredicto calculado con los
                // pesos del cliente de uno calculado con los del sistema cuando
                // ambos coinciden en valor. En auditoria esa diferencia importa:
                // decir "el cliente lo configuro asi" no es lo mismo que decir
                // "nadie lo configuro".
                'weights_source' => $personalizados === null ? 'sistema' : 'personalizados',
                'observation_threshold' => $umbral,
                'rules_applied' => $rulesApplied,
                'formula_version' => 2,
                'rule' => 'puntaje de calidad = 100 - suma(peso por severidad); los bloqueantes no penalizan el puntaje pero fuerzan rechazo; cero reglas resueltas produce "sin evaluar"',
            ],
        ];
    }

    /**
     * Lee el ajuste scoring_weights y lo deja utilizable, o devuelve null si no
     * hay nada que aplicar.
     *
     * El campo de parametros del panel es un KeyValue: guarda cadenas, no
     * estructuras. Antes esta clase exigia un arreglo, asi que cualquier valor
     * escrito desde el panel se descartaba y la validacion seguia usando los
     * pesos del sistema. Sin error y sin aviso: el usuario cambiaba el numero,
     * guardaba, revalidaba y obtenia el mismo puntaje, sin forma de saber por
     * que.
     *
     * Ahora se acepta tambien el JSON en texto, que es lo unico que el panel
     * puede producir. Y cuando el valor existe pero no se puede interpretar, se
     * registra en el log: un ajuste ignorado en silencio es peor que uno que
     * falla, porque nadie lo investiga.
     *
     * @return array<string, mixed>|null
     */
    private function pesosDeclarados(?Brand $brand): ?array
    {
        $valor = $brand?->setting('scoring_weights');

        if ($valor === null || $valor === '' || $valor === []) {
            return null;
        }

        if (is_array($valor)) {
            return $valor;
        }

        if (is_string($valor)) {
            $decodificado = json_decode($valor, true);

            if (is_array($decodificado)) {
                return $decodificado;
            }
        }

        Log::warning('scoring_weights no se pudo interpretar y se ignoro', [
            'brand_id' => $brand?->getKey(),
            'tipo' => get_debug_type($valor),
            'valor' => is_scalar($valor) ? mb_substr((string) $valor, 0, 200) : null,
            'esperado' => 'un objeto JSON como {"blocking":0,"major":20,"minor":8,"info":0}',
        ]);

        return null;
    }

    /**
     * Combina los pesos declarados con los del sistema.
     *
     * Solo se toman las cuatro claves conocidas: una clave escrita de otra
     * forma —"mayor" en vez de "major"— no cambia nada, y como eso tampoco
     * produciria error, se avisa.
     *
     * Los valores se acotan a cero por abajo. Un peso negativo no es una
     * exigencia menor: sumaria puntaje por incumplir, y una pieza con muchos
     * hallazgos terminaria con mejor nota que una impecable.
     *
     * @param  array<string, mixed>|null  $personalizados
     * @return array<string, float>
     */
    private function weightsFor(?array $personalizados): array
    {
        if ($personalizados === null) {
            return self::DEFAULT_WEIGHTS;
        }

        $desconocidas = array_diff(array_keys($personalizados), array_keys(self::DEFAULT_WEIGHTS));

        if ($desconocidas !== []) {
            Log::warning('scoring_weights trae claves que el sistema no usa', [
                'claves' => array_values($desconocidas),
                'validas' => array_keys(self::DEFAULT_WEIGHTS),
            ]);
        }

        $pesos = [];

        foreach (self::DEFAULT_WEIGHTS as $clave => $porOmision) {
            $valor = $personalizados[$clave] ?? null;

            if ($valor === null || ! is_numeric($valor)) {
                if ($valor !== null) {
                    Log::warning('scoring_weights: valor no numerico, se usa el del sistema', [
                        'clave' => $clave,
                        'valor' => is_scalar($valor) ? (string) $valor : get_debug_type($valor),
                    ]);
                }

                $pesos[$clave] = $porOmision;

                continue;
            }

            $pesos[$clave] = max(0.0, (float) $valor);
        }

        return $pesos;
    }
}
