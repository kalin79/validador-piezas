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
final class ContrastEvaluator implements Evaluator
{
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

        $umbral = (float) ($asset->brand?->setting('contrast_threshold') ?? $this->defaultThreshold);

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
