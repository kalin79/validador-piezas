<?php

declare(strict_types=1);

namespace App\Services\Validation\Evaluators;

use App\Enums\RuleCategory;
use App\Models\Asset;
use App\Models\Rule;
use App\Services\Validation\FindingDraft;
use App\Support\Color\ColorConverter;
use App\Support\Color\Rgb;
use Illuminate\Support\Collection;

/**
 * Evalua el contraste entre los dos colores dominantes de la pieza.
 *
 * Limitacion conocida y deliberada: sin conocer las zonas de texto, esto es
 * una aproximacion. Compara el par dominante asumiendo que uno hace de fondo
 * y otro de figura. Detecta el caso frecuente de texto claro sobre fondo
 * claro, pero no reemplaza una medicion sobre la caja de texto real.
 *
 * Se resuelve bien cuando el modelo de vision devuelva las coordenadas del
 * texto. Hasta entonces conviene mantener la regla en severidad Informativa:
 * es preferible una advertencia suave a un rechazo mal fundado.
 *
 * El hallazgo se emite por regla, no por evaluador. Antes se construia sin
 * ruleCode ni ruleId, y eso lo dejaba fuera de las metricas de Calidad del
 * motor, que agrupan por findings.rule_code. En la practica significaba que
 * esta regla no se podia calibrar: se veian sus hallazgos pero no su tasa de
 * acierto.
 */
final class ContrastEvaluator implements Evaluator, ReportsUndetermined
{
    public function undetermined(Asset $asset, Collection $rules, ?string $channel): array
    {
        $motivo = match (true) {
            count($asset->extracted_palette ?? []) < 2 => 'No hay al menos dos colores extraidos de la pieza: no se pudo medir el contraste.',
            $this->umbral($asset) === null => 'El umbral contrast_threshold configurado no es un numero valido: no se midio el contraste.',
            default => null,
        };

        if ($motivo === null) {
            return [];
        }

        return $rules->mapWithKeys(fn (Rule $r): array => [$r->code => $motivo])->all();
    }

    /**
     * Umbral efectivo, o null si el configurado no se puede interpretar.
     *
     * Antes se hacia (float) sobre el valor crudo: una cadena vacia o "abc"
     * daban 0.0, y con umbral cero el evaluador nunca disparaba. La regla
     * quedaba reportada como cumplida sin haberse medido.
     */
    private function umbral(Asset $asset): ?float
    {
        $valor = $asset->brand?->setting('contrast_threshold');

        if ($valor === null || $valor === '') {
            return $this->defaultThreshold;
        }

        if (! is_numeric($valor) || (float) $valor <= 0.0 || (float) $valor > 21.0) {
            return null;
        }

        return (float) $valor;
    }

    public function __construct(
        private float $defaultThreshold = 4.5,
    ) {}

    public function handles(): array
    {
        return [RuleCategory::Typography->value];
    }

    public function evaluate(Asset $asset, Collection $rules, ?string $channel): array
    {
        if ($rules->isEmpty()) {
            return [];
        }

        $paleta = $asset->extracted_palette ?? [];

        if (count($paleta) < 2) {
            return [];
        }

        $umbral = $this->umbral($asset);

        if ($umbral === null) {
            return [];
        }

        $fondo = Rgb::fromHex((string) $paleta[0]['hex']);
        $figura = Rgb::fromHex((string) $paleta[1]['hex']);

        $ratio = ColorConverter::contrastRatio($fondo, $figura);

        if ($ratio >= $umbral) {
            return [];
        }

        $findings = [];

        foreach ($rules as $rule) {
            $findings[] = new FindingDraft(
                category: RuleCategory::Typography,
                severity: $rule->severity,
                description: sprintf(
                    'Los dos colores dominantes tienen un contraste de %.2f:1, por debajo del umbral de %.1f:1. Si hay texto entre ellos, sera dificil de leer.',
                    $ratio,
                    $umbral,
                ),
                ruleCode: $rule->code,
                ruleId: $rule->id,
                evidence: sprintf('%s sobre %s', $figura->toHex(), $fondo->toHex()),
                evidenceData: [
                    'background_hex' => $fondo->toHex(),
                    'foreground_hex' => $figura->toHex(),
                    'contrast_ratio' => round($ratio, 3),
                    'threshold' => $umbral,
                    'threshold_source' => $asset->brand?->setting('contrast_threshold') !== null
                        ? 'configurado en la marca o el cliente'
                        : 'valor por defecto del sistema',
                    'note' => 'Aproximacion sobre colores dominantes; no se detectaron zonas de texto.',
                ],
                suggestion: 'Verifica manualmente la legibilidad del texto, u oscurece el fondo detras de el.',
            );
        }

        return $findings;
    }
}
