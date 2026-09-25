<?php

declare(strict_types=1);

namespace App\Services\Validation\Evaluators;

use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Models\Asset;
use App\Models\Palette;
use App\Models\PaletteColor;
use App\Models\Rule;
use App\Services\Validation\FindingDraft;
use App\Support\Color\ColorConverter;
use App\Support\Color\DeltaE;
use App\Support\Color\Lab;
use Illuminate\Support\Collection;

/**
 * Compara los colores dominantes de la pieza contra la paleta autorizada.
 *
 * Dos decisiones de fondo:
 *
 * 1. La comparacion es por distancia CIEDE2000 en CIELAB, no por igualdad de
 *    hexadecimal. Un JPEG comprimido nunca devuelve el hex exacto de la guia
 *    de marca, asi que una comparacion literal marcaria como error cualquier
 *    pieza real.
 *
 * 2. Solo se evaluan los colores con presencia significativa. Un pixel suelto
 *    del color de un competidor en el borde de un degradado no es un uso de
 *    ese color; exigir pureza absoluta genera ruido que hace que nadie lea
 *    los hallazgos.
 */
final class PaletteEvaluator implements Evaluator, ReportsUndetermined
{
    public function undetermined(Asset $asset, Collection $rules, ?string $channel): array
    {
        if (($asset->extracted_palette ?? []) === []) {
            return $rules->mapWithKeys(fn (Rule $r): array => [
                $r->code => 'No se pudo extraer la paleta de la pieza: no hay colores contra los cuales comparar.',
            ])->all();
        }

        $motivos = [];

        foreach ($rules as $rule) {
            $palette = $this->paletteFor($rule, $asset);

            if ($palette === null) {
                $motivos[$rule->code] = 'La regla no tiene paleta asociada.';

                continue;
            }

            if ($palette->colors()->where('is_forbidden', false)->doesntExist()) {
                $motivos[$rule->code] = 'La paleta asociada no tiene colores autorizados: solo se pudieron revisar los prohibidos.';
            }
        }

        return $motivos;
    }

    public function __construct(
        private float $minShare = 0.02,
        private float $forbiddenMinShare = 0.005,
        private float $coverageMinorThreshold = 0.15,
        private float $coverageMajorThreshold = 0.30,
        private float $coverageBlockingThreshold = 0.60,
    ) {}

    public function handles(): array
    {
        return [RuleCategory::Palette->value];
    }

    public function evaluate(Asset $asset, Collection $rules, ?string $channel): array
    {
        $extraidos = $asset->extracted_palette ?? [];

        if ($extraidos === []) {
            return [];
        }

        $findings = [];

        foreach ($rules as $rule) {
            $palette = $this->paletteFor($rule, $asset);

            if ($palette === null) {
                $findings[] = new FindingDraft(
                    category: RuleCategory::Palette,
                    severity: Severity::Info,
                    description: "La regla {$rule->code} exige una paleta pero no tiene ninguna asociada, no se pudo evaluar.",
                    ruleCode: $rule->code,
                    ruleId: $rule->id,
                    suggestion: 'Asocia una paleta a la regla desde el panel.',
                );

                continue;
            }

            $colores = $palette->colors()->get();
            $autorizados = $colores->where('is_forbidden', false);
            $prohibidos = $colores->where('is_forbidden', true);

            $superficieDesviada = 0.0;

            foreach ($extraidos as $extraido) {
                $lab = $this->labOf($extraido);
                $share = (float) ($extraido['share'] ?? 0);
                $hex = (string) ($extraido['hex'] ?? '');

                // a) Colores explicitamente prohibidos: umbral mas bajo.
                $prohibido = $this->closest($lab, $prohibidos);

                if ($prohibido !== null
                    && $share >= $this->forbiddenMinShare
                    && $prohibido['distance'] <= $prohibido['color']->effectiveTolerance()) {
                    $findings[] = new FindingDraft(
                        category: RuleCategory::Palette,
                        severity: Severity::Blocking,
                        description: sprintf(
                            'La pieza usa un color prohibido: %s (%s), que ocupa el %s de la imagen.',
                            $prohibido['color']->name,
                            $prohibido['color']->hex,
                            $this->pct($share),
                        ),
                        ruleCode: $rule->code,
                        ruleId: $rule->id,
                        evidence: "{$hex} a Delta E ".round($prohibido['distance'], 2)." de {$prohibido['color']->hex}",
                        evidenceData: [
                            'detected_hex' => $hex,
                            'forbidden_hex' => $prohibido['color']->hex,
                            'forbidden_name' => $prohibido['color']->name,
                            'delta_e' => round($prohibido['distance'], 3),
                            'share' => round($share, 4),
                        ],
                        suggestion: 'Reemplaza ese color por uno de la paleta autorizada.',
                    );

                    continue;
                }

                if ($share < $this->minShare) {
                    continue;
                }

                // b) Colores fuera de la paleta autorizada.
                $cercano = $this->closest($lab, $autorizados);

                if ($cercano === null) {
                    continue;
                }

                $tolerancia = $cercano['color']->effectiveTolerance();

                if ($cercano['distance'] <= $tolerancia) {
                    continue;
                }

                // Cuanto mas presencia tiene el color desviado, mas grave es.
                $severidad = $share >= 0.15 ? Severity::Major : Severity::Minor;

                $findings[] = new FindingDraft(
                    category: RuleCategory::Palette,
                    severity: $severidad,
                    description: sprintf(
                        'El color %s ocupa el %s de la pieza y no pertenece a la paleta autorizada.',
                        $hex,
                        $this->pct($share),
                    ),
                    ruleCode: $rule->code,
                    ruleId: $rule->id,
                    evidence: sprintf(
                        '%s esta a Delta E %.2f de %s (%s), tolerancia %.2f',
                        $hex,
                        $cercano['distance'],
                        $cercano['color']->name,
                        $cercano['color']->hex,
                        $tolerancia,
                    ),
                    evidenceData: [
                        'detected_hex' => $hex,
                        'nearest_hex' => $cercano['color']->hex,
                        'nearest_name' => $cercano['color']->name,
                        'delta_e' => round($cercano['distance'], 3),
                        'tolerance' => round($tolerancia, 2),
                        'share' => round($share, 4),
                    ],
                    suggestion: sprintf(
                        'El color autorizado mas parecido es %s (%s). Si la desviacion es intencional, agrega el color a la paleta.',
                        $cercano['color']->name,
                        $cercano['color']->hex,
                    ),
                );

                $superficieDesviada += $share;
            }

            // Hallazgo agregado sobre la cobertura total.
            //
            // Evaluar color por color oculta el problema de fondo: seis
            // observaciones menores suenan a detalles, pero si entre todas
            // suman la mitad de la pieza, lo que hay no es una desviacion sino
            // una pieza que no responde a la identidad de la marca. Esa
            // diferencia debe verse en el veredicto, no deducirse sumando.
            $findings = array_merge(
                $findings,
                $this->coverageFinding($rule, $superficieDesviada)
            );
        }

        return $findings;
    }

    /**
     * @return array<int, FindingDraft>
     */
    private function coverageFinding(Rule $rule, float $superficieDesviada): array
    {
        if ($superficieDesviada < $this->coverageMinorThreshold) {
            return [];
        }

        $severidad = match (true) {
            $superficieDesviada >= $this->coverageBlockingThreshold => Severity::Blocking,
            $superficieDesviada >= $this->coverageMajorThreshold => Severity::Major,
            default => Severity::Minor,
        };

        $mensaje = match ($severidad) {
            Severity::Blocking => 'La pieza no responde a la identidad cromatica de la marca.',
            Severity::Major => 'Una parte sustancial de la pieza no usa colores de marca.',
            default => 'Hay presencia apreciable de colores ajenos a la paleta.',
        };

        return [new FindingDraft(
            category: RuleCategory::Palette,
            severity: $severidad,
            description: sprintf(
                '%s El %s de la superficie corresponde a colores fuera de la paleta autorizada.',
                $mensaje,
                $this->pct($superficieDesviada),
            ),
            ruleCode: $rule->code,
            ruleId: $rule->id,
            evidence: sprintf('%s de superficie fuera de paleta', $this->pct($superficieDesviada)),
            evidenceData: [
                'off_palette_share' => round($superficieDesviada, 4),
                'off_palette_percent' => round($superficieDesviada * 100, 2),
                'thresholds' => [
                    'minor' => $this->coverageMinorThreshold,
                    'major' => $this->coverageMajorThreshold,
                    'blocking' => $this->coverageBlockingThreshold,
                ],
            ],
            suggestion: $severidad === Severity::Blocking
                ? 'Revisa si la pieza corresponde a esta marca. Una desviacion de esta magnitud suele indicar que se cargo bajo la marca equivocada.'
                : 'Ajusta los colores dominantes a la paleta autorizada, o amplia la paleta si el uso es intencional.',
        )];
    }

    /**
     * Copia de las paletas con las que se midio, por regla.
     *
     * La paleta es editable: sin esta copia, un veredicto de hoy se explicaria
     * manana con colores y tolerancias que ya no son los que se usaron.
     *
     * @param  Collection<int, Rule>  $rules
     * @return array<string, array<string, mixed>|null>
     */
    public function snapshot(Asset $asset, Collection $rules): array
    {
        $copia = [];

        foreach ($rules as $rule) {
            $palette = $this->paletteFor($rule, $asset);

            $copia[$rule->code] = $palette === null ? null : [
                'palette_id' => $palette->id,
                'name' => $palette->name,
                'colors' => $palette->colors->map(fn (PaletteColor $c): array => [
                    'hex' => $c->hex,
                    'name' => $c->name,
                    'tolerance' => $c->effectiveTolerance(),
                    'forbidden' => (bool) $c->is_forbidden,
                ])->values()->all(),
            ];
        }

        return $copia;
    }

    private function paletteFor(Rule $rule, Asset $asset): ?Palette
    {
        if ($rule->palette_id !== null) {
            return Palette::query()->with('colors')->find($rule->palette_id);
        }

        return Palette::query()
            ->with('colors')
            ->where('brand_id', $asset->brand_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /** @param array<string, mixed> $extraido */
    private function labOf(array $extraido): Lab
    {
        if (isset($extraido['lab']['l'])) {
            return Lab::fromArray($extraido['lab']);
        }

        return ColorConverter::hexToLab((string) $extraido['hex']);
    }

    /**
     * @param  Collection<int, PaletteColor>  $colores
     * @return array{color: PaletteColor, distance: float}|null
     */
    private function closest(Lab $lab, Collection $colores): ?array
    {
        $mejor = null;

        foreach ($colores as $color) {
            $distancia = DeltaE::ciede2000($lab, ColorConverter::hexToLab($color->hex));

            if ($mejor === null || $distancia < $mejor['distance']) {
                $mejor = ['color' => $color, 'distance' => $distancia];
            }
        }

        return $mejor;
    }

    private function pct(float $share): string
    {
        return number_format($share * 100, 1).'%';
    }
}
