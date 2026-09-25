<?php

declare(strict_types=1);

namespace App\Services\Validation\Evaluators;

use App\Enums\LogoPosition;
use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Models\Asset;
use App\Models\BrandAsset;
use App\Models\Rule;
use App\Services\Validation\FindingDraft;

/**
 * Verifica las reglas de uso del logotipo sobre las coordenadas que devolvio
 * el modelo de vision.
 *
 * Division deliberada del trabajo: el modelo hace lo que hace bien, que es
 * reconocer donde esta el logo; el codigo hace lo que hace bien, que es medir.
 * Un porcentaje de ancho o una distancia al borde no son cuestion de opinion,
 * y calcularlas aqui las vuelve reproducibles y auditables.
 */
final class LogoEvaluator
{
    private const MIN_CONFIDENCE_ABSENCE = 0.6;

    /**
     * Compatibilidad: solo los hallazgos.
     *
     * @param  array<string, mixed>  $logoData
     * @return array<int, FindingDraft>
     */
    public function evaluate(Asset $asset, array $logoData, ?string $channel, ?Rule $regla = null): array
    {
        return $this->evaluateWithCoverage($asset, $logoData, $channel, $regla)['findings'];
    }

    /**
     * @param  array<string, mixed>  $logoData  bloque 'logo' de la salida del modelo
     * @param  Rule|null  $regla  regla de categoria "activos obligatorios" que
     *                            ampara estos hallazgos, si el conjunto la tiene
     * @return array{findings: array<int, FindingDraft>, undetermined: string|null}
     */
    public function evaluateWithCoverage(Asset $asset, array $logoData, ?string $channel, ?Rule $regla = null): array
    {
        /*
         * Este evaluador no recibe reglas: mide contra los activos de marca y
         * las coordenadas que devolvio el modelo. Pero sus hallazgos si tienen
         * que apuntar a una regla, por dos motivos.
         *
         * El primero es de auditoria: el hallazgo de presencia es BLOQUEANTE, y
         * rechazar una pieza sin poder decir bajo que regla no se defiende ante
         * nadie.
         *
         * El segundo es de calibracion: CalidadDelMotor filtra por
         * findings.rule_code, asi que sin codigo estas validaciones nunca
         * entraban en las metricas de precision y no habia forma de saber si
         * aciertan.
         *
         * Se toma la regla de categoria "activos obligatorios" del conjunto
         * efectivo. Si el conjunto no tiene ninguna, los hallazgos salen sin
         * codigo como antes: es preferible reportar el problema sin etiqueta a
         * no reportarlo.
         */
        $codigo = $regla?->code;
        $reglaId = $regla?->id;
        $obligatorios = BrandAsset::query()
            ->where('brand_id', $asset->brand_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (BrandAsset $a): bool => $a->appliesToChannel($channel))
            // El modelo devuelve un solo bloque 'logo'. Usarlo para decidir si
            // hay un sello o una marca de agua es afirmar algo que nadie midio.
            ->filter(fn (BrandAsset $a): bool => $a->type->isLogo());

        if ($obligatorios->isEmpty()) {
            return ['findings' => [], 'undetermined' => null];
        }

        $detectado = ($logoData['detected'] ?? null) === true;
        $confianza = is_numeric($logoData['confidence'] ?? null) ? (float) $logoData['confidence'] : null;
        $caja = $this->cajaValida($logoData['bounding_box'] ?? null);
        $sinDeterminar = null;

        if (! $detectado && ($confianza === null || $confianza < self::MIN_CONFIDENCE_ABSENCE)) {
            // Una ausencia dudosa no rechaza la pieza: se manda a revision.
            return [
                'findings' => [],
                'undetermined' => sprintf(
                    'El modelo no detecto el logo, pero con confianza %s: no alcanza para afirmar que falta.',
                    $confianza === null ? 'no informada' : number_format($confianza, 2),
                ),
            ];
        }

        if ($detectado && $caja === null) {
            $sinDeterminar = 'El modelo detecto el logo pero sus coordenadas no son validas (se esperan x, y, width, height entre 0 y 1): no se midieron tamano, resguardo ni posicion.';
        }

        $findings = [];

        foreach ($obligatorios as $activo) {
            // 1. Presencia.
            if (! $detectado) {
                if ($activo->is_required) {
                    $findings[] = new FindingDraft(
                        category: RuleCategory::RequiredAssets,
                        severity: Severity::Blocking,
                        description: sprintf('No se detecto el %s en la pieza, y su presencia es obligatoria.', $activo->name),
                        ruleCode: $codigo,
                        ruleId: $reglaId,
                        evidence: $logoData['notes'] ?? null,
                        evidenceData: [
                            'brand_asset_id' => $activo->id,
                            'detected' => false,
                            'confidence' => round((float) $confianza, 3),
                        ],
                        suggestion: 'Incorpora el logotipo respetando las reglas de tamano y area de resguardo.',
                        origin: \App\Enums\FindingOrigin::Ai,
                    );
                }

                continue;
            }

            if ($caja === null) {
                continue;
            }

            ['x' => $x, 'y' => $y, 'width' => $ancho, 'height' => $alto] = $caja;

            // 2. Tamano minimo, como porcentaje del ancho de la pieza.
            if ($activo->min_width_percent !== null) {
                $porcentaje = $ancho * 100;

                if ($porcentaje < $activo->min_width_percent) {
                    $findings[] = new FindingDraft(
                        category: RuleCategory::RequiredAssets,
                        severity: Severity::Major,
                        description: sprintf(
                            'El %s ocupa el %.1f%% del ancho y el minimo establecido es %.1f%%. A ese tamano se vuelve ilegible en movil.',
                            $activo->name,
                            $porcentaje,
                            $activo->min_width_percent,
                        ),
                        ruleCode: $codigo,
                        ruleId: $reglaId,
                        evidenceData: [
                            'brand_asset_id' => $activo->id,
                            'width_percent' => round($porcentaje, 2),
                            'min_width_percent' => $activo->min_width_percent,
                            'bounding_box' => $caja,
                        ],
                        suggestion: sprintf('Amplia el logotipo hasta al menos el %.1f%% del ancho.', $activo->min_width_percent),
                        origin: \App\Enums\FindingOrigin::Ai,
                    );
                }
            }

            // 3. Area de resguardo: espacio libre entre el logo y los bordes,
            //    medido en multiplos de la altura del propio logo.
            if ($activo->clear_space_ratio !== null && $alto > 0) {
                $resguardo = $alto * $activo->clear_space_ratio;

                $margenes = [
                    'izquierdo' => $x,
                    'superior' => $y,
                    'derecho' => 1.0 - ($x + $ancho),
                    'inferior' => 1.0 - ($y + $alto),
                ];

                $insuficientes = array_filter(
                    $margenes,
                    static fn (float $m): bool => $m < $resguardo
                );

                if ($insuficientes !== []) {
                    $findings[] = new FindingDraft(
                        category: RuleCategory::RequiredAssets,
                        severity: Severity::Minor,
                        description: sprintf(
                            'El %s no respeta el area de resguardo por el lado %s. Se exige un margen equivalente a %.2f veces su altura.',
                            $activo->name,
                            implode(' y ', array_keys($insuficientes)),
                            $activo->clear_space_ratio,
                        ),
                        ruleCode: $codigo,
                        ruleId: $reglaId,
                        evidenceData: [
                            'brand_asset_id' => $activo->id,
                            'required_clear_space' => round($resguardo, 4),
                            'margins' => array_map(static fn (float $m): float => round($m, 4), $margenes),
                            'insufficient_sides' => array_keys($insuficientes),
                        ],
                        suggestion: 'Separa el logotipo de los bordes o de los elementos que lo rodean.',
                        origin: \App\Enums\FindingOrigin::Ai,
                    );
                }
            }

            // 4. Posicion permitida, sobre la malla de 3x3.
            if (filled($activo->allowed_positions)) {
                $posicion = LogoPosition::fromPoint($x + $ancho / 2, $y + $alto / 2);

                if (! $activo->allowsPosition($posicion)) {
                    $permitidas = collect((array) $activo->allowed_positions)
                        ->map(fn (string $p): string => LogoPosition::from($p)->label())
                        ->implode(', ');

                    $findings[] = new FindingDraft(
                        category: RuleCategory::RequiredAssets,
                        severity: Severity::Minor,
                        description: sprintf(
                            'El %s esta en la zona %s y las posiciones permitidas son: %s.',
                            $activo->name,
                            $posicion->label(),
                            $permitidas,
                        ),
                        ruleCode: $codigo,
                        ruleId: $reglaId,
                        evidenceData: [
                            'brand_asset_id' => $activo->id,
                            'detected_position' => $posicion->value,
                            'allowed_positions' => $activo->allowed_positions,
                            'center' => ['x' => round($x + $ancho / 2, 4), 'y' => round($y + $alto / 2, 4)],
                        ],
                        suggestion: 'Reubica el logotipo en alguna de las zonas permitidas.',
                        origin: \App\Enums\FindingOrigin::Ai,
                    );
                }
            }
        }

        return ['findings' => $findings, 'undetermined' => $sinDeterminar];
    }

    /**
     * Normaliza la caja o devuelve null si no se puede medir sobre ella.
     *
     * Antes los faltantes se convertian en cero ("el logo ocupa el 0.0% del
     * ancho") y las coordenadas en pixeles daban margenes negativos. Las dos
     * cosas producian hallazgos sobre datos que no existian.
     *
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function cajaValida(mixed $caja): ?array
    {
        if (! is_array($caja)) {
            return null;
        }

        $valores = [];

        foreach (['x', 'y', 'width', 'height'] as $clave) {
            if (! is_numeric($caja[$clave] ?? null)) {
                return null;
            }

            $v = (float) $caja[$clave];

            if ($v < 0.0 || $v > 1.0) {
                return null;
            }

            $valores[$clave] = $v;
        }

        $tolerancia = 0.001;

        if ($valores['width'] <= 0.0 || $valores['height'] <= 0.0
            || $valores['x'] + $valores['width'] > 1.0 + $tolerancia
            || $valores['y'] + $valores['height'] > 1.0 + $tolerancia) {
            return null;
        }

        return $valores;
    }
}
