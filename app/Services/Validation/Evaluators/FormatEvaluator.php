<?php

declare(strict_types=1);

namespace App\Services\Validation\Evaluators;

use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Models\Asset;
use App\Models\Rule;
use App\Services\Validation\FindingDraft;
use Illuminate\Support\Collection;

/**
 * Valida dimensiones, relacion de aspecto y peso contra el preset del canal.
 *
 * Es el evaluador mas barato del pipeline y el que mas trabajo ahorra: una
 * pieza con la relacion de aspecto equivocada se va a recortar sola al
 * publicarse, y ningun analisis de copy vale nada si la mitad de la pieza
 * no se va a ver.
 */
final class FormatEvaluator implements Evaluator
{
    public function handles(): array
    {
        return [RuleCategory::Composition->value];
    }

    /**
     * @param  Collection<int, Rule>  $rules
     * @return array<int, FindingDraft>
     */
    public function evaluate(Asset $asset, Collection $rules, ?string $channel): array
    {
        if ($channel === null) {
            return [];
        }

        // Los hallazgos de formato describen la pieza, no cada regla: si hay
        // dos reglas de composicion, la relacion de aspecto sigue siendo una
        // sola. Se toma la primera como la regla que los ampara, para que
        // queden ligados a un codigo y entren en las metricas de calidad.
        $regla = $rules->first();
        $codigo = $regla?->code;
        $reglaId = $regla?->id;

        $preset = config("channels.presets.{$channel}");

        if ($preset === null) {
            return [new FindingDraft(
                category: RuleCategory::Composition,
                severity: Severity::Info,
                description: "No hay especificacion registrada para el canal '{$channel}', no se validaron dimensiones.",
                ruleCode: $codigo,
                ruleId: $reglaId,
                evidenceData: ['channel' => $channel],
                suggestion: 'Agrega el canal a config/channels.php para que se valide el formato.',
            )];
        }

        $findings = [];
        $ancho = $asset->width;
        $alto = $asset->height;

        if ($ancho === null || $alto === null) {
            return [new FindingDraft(
                category: RuleCategory::Composition,
                severity: Severity::Major,
                description: 'No se pudieron leer las dimensiones de la pieza.',
                ruleCode: $codigo,
                ruleId: $reglaId,
                evidenceData: ['mime_type' => $asset->mime_type],
            )];
        }

        // 1. Relacion de aspecto.
        if ($preset['ratio'] !== null) {
            $ratio = $ancho / $alto;
            $desvio = abs($ratio - $preset['ratio']) / $preset['ratio'];
            $tolerancia = (float) config('channels.tolerance_ratio', 0.02);

            if ($desvio > $tolerancia) {
                $findings[] = new FindingDraft(
                    category: RuleCategory::Composition,
                    severity: Severity::Major,
                    description: sprintf(
                        'La relacion de aspecto no corresponde a %s. La pieza es %s y el canal espera %s.',
                        $preset['label'],
                        $this->formatRatio($ratio),
                        $preset['ratio_label'],
                    ),
                    ruleCode: $codigo,
                ruleId: $reglaId,
                evidence: "{$ancho}x{$alto} px",
                    evidenceData: [
                        'width' => $ancho,
                        'height' => $alto,
                        'ratio' => round($ratio, 4),
                        'expected_ratio' => round((float) $preset['ratio'], 4),
                        'expected_ratio_label' => $preset['ratio_label'],
                        'deviation_percent' => round($desvio * 100, 2),
                    ],
                    suggestion: sprintf(
                        'Exporta a %dx%d px o cualquier medida que conserve la relacion %s.',
                        $preset['width'],
                        $preset['height'],
                        $preset['ratio_label'],
                    ),
                );
            }
        }

        // 2. Resolucion insuficiente o excesiva.
        if ($preset['width'] !== null) {
            $escala = $ancho / $preset['width'];
            $min = (float) config('channels.min_scale', 0.5);
            $max = (float) config('channels.max_scale', 3.0);

            if ($escala < $min) {
                $findings[] = new FindingDraft(
                    category: RuleCategory::Composition,
                    severity: Severity::Major,
                    description: sprintf(
                        'Resolucion insuficiente: %d px de ancho frente a los %d px recomendados. Se vera pixelada.',
                        $ancho,
                        $preset['width'],
                    ),
                    ruleCode: $codigo,
                ruleId: $reglaId,
                evidence: "{$ancho}x{$alto} px",
                    evidenceData: [
                        'width' => $ancho,
                        'recommended_width' => $preset['width'],
                        'scale' => round($escala, 3),
                    ],
                    suggestion: "Reexporta con al menos {$preset['width']} px de ancho.",
                );
            } elseif ($escala > $max) {
                $findings[] = new FindingDraft(
                    category: RuleCategory::Composition,
                    severity: Severity::Minor,
                    description: sprintf(
                        'Resolucion muy por encima de lo necesario: %d px frente a %d px recomendados. La plataforma la va a recomprimir.',
                        $ancho,
                        $preset['width'],
                    ),
                    ruleCode: $codigo,
                ruleId: $reglaId,
                evidence: "{$ancho}x{$alto} px",
                    evidenceData: [
                        'width' => $ancho,
                        'recommended_width' => $preset['width'],
                        'scale' => round($escala, 3),
                    ],
                    suggestion: "Reducir a {$preset['width']} px evita que la plataforma la recomprima con su propio algoritmo.",
                );
            }
        }

        // 3. Peso del archivo.
        if (isset($preset['max_bytes']) && $asset->file_size > $preset['max_bytes']) {
            $findings[] = new FindingDraft(
                category: RuleCategory::Composition,
                severity: Severity::Major,
                description: sprintf(
                    'El archivo pesa %s y el canal admite hasta %s.',
                    $this->humanBytes((int) $asset->file_size),
                    $this->humanBytes((int) $preset['max_bytes']),
                ),
                ruleCode: $codigo,
                ruleId: $reglaId,
                evidenceData: [
                    'file_size' => (int) $asset->file_size,
                    'max_bytes' => (int) $preset['max_bytes'],
                ],
                suggestion: 'Exporta con mayor compresion o reduce las dimensiones.',
            );
        }

        return $findings;
    }

    private function formatRatio(float $ratio): string
    {
        $conocidos = [
            '1:1' => 1 / 1,
            '4:5' => 4 / 5,
            '9:16' => 9 / 16,
            '16:9' => 16 / 9,
            '1.91:1' => 1.91,
            '3:4' => 3 / 4,
            '2:3' => 2 / 3,
        ];

        foreach ($conocidos as $etiqueta => $valor) {
            if (abs($ratio - $valor) / $valor < 0.02) {
                return $etiqueta;
            }
        }

        return number_format($ratio, 2).':1';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        return round($bytes / 1024).' KB';
    }
}
