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

    /*
     * Bajo este puntaje la pieza se rechaza aunque no haya ningun bloqueante.
     *
     * Cincuenta es la mitad de las reglas incumplidas en peso. A esa altura ya
     * no es una pieza con observaciones: es una pieza que hay que rehacer, y
     * decir "aprobada" de ella —aunque sea con observaciones— le quita sentido
     * a la palabra.
     */
    private const DEFAULT_REJECTION_THRESHOLD = 50.0;

    /**
     * @param  Collection<int, Finding>  $findings
     * @param  int|null  $rulesApplied  cuantas reglas se resolvieron para la pieza.
     *                                  Null conserva el comportamiento anterior.
     * @param  array<string, array{outcome: string, reason: string|null}>|null  $pending
     *                                  reglas aplicables que NO quedaron evaluadas.
     *                                  Null = cobertura desconocida (compatibilidad).
     * @return array<string, mixed> atributos listos para crear el Verdict
     */
    public function calculate(
        Collection $findings,
        ?Brand $brand = null,
        ?int $rulesApplied = null,
        ?array $pending = null,
    ): array {
        $personalizados = $this->pesosDeclarados($brand);
        $pesos = $this->weightsFor($personalizados);
        $umbral = $this->umbral($brand, 'observation_threshold', self::DEFAULT_OBSERVATION_THRESHOLD);
        $umbralRechazo = $this->umbral($brand, 'rejection_threshold', self::DEFAULT_REJECTION_THRESHOLD);

        /*
         * Los dos umbrales son cortes sobre la misma recta y tienen que estar
         * en orden. Con rechazo por encima de observaciones, la banda de
         * observaciones desaparece sin que nadie lo note. Se recorta y se
         * avisa: es preferible rechazar de menos que borrar en silencio un
         * estado entero del sistema.
         */
        if ($umbralRechazo > $umbral) {
            Log::warning('rejection_threshold es mayor que observation_threshold y se recorto', [
                'brand_id' => $brand?->id,
                'rejection_threshold' => $umbralRechazo,
                'observation_threshold' => $umbral,
            ]);

            $umbralRechazo = $umbral;
        }

        $bloqueantes = $findings->where('severity', Severity::Blocking)->count();
        $mayores = $findings->where('severity', Severity::Major)->count();
        $menores = $findings->where('severity', Severity::Minor)->count();

        /*
         * Una regla resta una sola vez, por su hallazgo mas grave.
         *
         * Decision de negocio (25/09/2026). Antes restaba cada hallazgo: la
         * regla de paleta generaba uno por color fuera de la guia mas uno de
         * cobertura que resumia esos mismos colores, y sola le quitaba 50
         * puntos a una pieza con fotografia. El puntaje medía cuantos colores
         * tiene la foto, no cuantas reglas se incumplen. Todos los hallazgos
         * se siguen mostrando como detalle.
         *
         * Los hallazgos sin codigo de regla (avisos sueltos) restan cada uno.
         */
        $penalizacion = 0.0;
        $peorPorRegla = [];

        foreach ($findings as $finding) {
            $peso = $pesos[$finding->severity->value] ?? 0.0;
            $codigo = $finding->rule_code;

            if (blank($codigo)) {
                $penalizacion += $peso;

                continue;
            }

            $peorPorRegla[$codigo] = max($peorPorRegla[$codigo] ?? 0.0, $peso);
        }

        $penalizacion += array_sum($peorPorRegla);

        $puntaje = max(0.0, 100.0 - $penalizacion);

        // Cero reglas resueltas no es una pieza impecable: es una pieza que
        // nadie midio. Aprobarla con 100 puntos es el peor error posible en un
        // sistema de auditoria, porque construye confianza sobre nada. Se
        // distingue explicitamente.
        $pendientes = $pending ?? [];
        $evaluadas = $rulesApplied === null ? null : max(0, $rulesApplied - count($pendientes));

        $estado = match (true) {
            $rulesApplied === 0 => VerdictStatus::NotEvaluated,
            // Un incumplimiento real es real aunque otras reglas no se hayan
            // podido verificar: el rechazo se sostiene con lo que si se midio.
            $bloqueantes > 0 => VerdictStatus::Rejected,
            // El bloqueante dice "esto no puede salir". El puntaje bajo dice
            // "esto esta mal hecho". Las dos cosas terminan en rechazo, pero
            // por motivos distintos, y el desglose permite separarlas despues.
            $puntaje < $umbralRechazo => VerdictStatus::Rejected,
            // Nada se pudo verificar: no es una pieza que cumple.
            $pendientes !== [] && $evaluadas === 0 => VerdictStatus::NotEvaluated,
            // Falla cerrado. Si alguna regla quedo sin verificar, no se puede
            // afirmar que la pieza cumple, ni siquiera "con observaciones".
            $pendientes !== [] => VerdictStatus::RequiresReview,
            $puntaje < $umbral => VerdictStatus::ApprovedWithObservations,
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
                'rejection_threshold' => $umbralRechazo,
                'rules_applied' => $rulesApplied,
                'rules_evaluated' => $evaluadas,
                'pending_rules' => $pending,
                'penalty_by_rule' => $peorPorRegla,
                // v5: cada regla resta una vez, por su hallazgo mas grave.
                // v4: cobertura por regla; con reglas sin verificar el estado
                // es "requiere revision" y nunca una aprobacion.
                'formula_version' => 5,
                'rule' => 'puntaje de calidad = 100 - suma(peso del hallazgo mas grave de cada regla) - suma(peso de hallazgos sin regla); los bloqueantes no penalizan el puntaje pero fuerzan rechazo; bajo rejection_threshold se rechaza aunque no haya bloqueantes; si alguna regla aplicable no se pudo verificar el estado es "requiere revision" (o "sin evaluar" si no se verifico ninguna); bajo observation_threshold se aprueba con observaciones; cero reglas resueltas produce "sin evaluar"',
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
    /**
     * Lee un umbral de los ajustes y lo deja utilizable.
     *
     * El campo de parametros del panel es un KeyValue y guarda cadenas, no
     * numeros. Sin esto, "85" llegaria como texto y una cadena vacia se
     * convertiria en 0.0, que en el umbral de rechazo significa "no rechazar
     * nunca": el silencio mas caro posible.
     */
    private function umbral(?Brand $brand, string $clave, float $porOmision): float
    {
        $valor = $brand?->setting($clave);

        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            if ($valor !== null && $valor !== '') {
                Log::warning('umbral no numerico, se usa el del sistema', [
                    'brand_id' => $brand?->id,
                    'clave' => $clave,
                    'valor' => $valor,
                ]);
            }

            return $porOmision;
        }

        $numero = (float) $valor;

        // Fuera de 0-100 no hay puntaje posible: un umbral de 150 rechazaria
        // hasta la pieza impecable.
        if ($numero < 0.0 || $numero > 100.0) {
            Log::warning('umbral fuera del rango 0-100, se usa el del sistema', [
                'brand_id' => $brand?->id,
                'clave' => $clave,
                'valor' => $numero,
            ]);

            return $porOmision;
        }

        return $numero;
    }

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
