<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\FindingOrigin;
use App\Enums\RuleCategory;
use App\Enums\RuleOutcome;
use App\Enums\RuleType;
use App\Models\Asset;
use App\Models\Rule;
use App\Services\ResolvedRuleSet;
use App\Services\Validation\Evaluators\ContrastEvaluator;
use App\Services\Validation\Evaluators\PaletteEvaluator;

/**
 * Separa los colores de la fotografia de los colores del diseno.
 *
 * El problema: la paleta se extrae de la imagen completa. En una pieza con
 * una foto (personas, madera, piel, ropa) esos colores salian como "fuera de
 * la paleta de marca", y el contraste se media entre los dos colores
 * dominantes aunque fueran la pared y el piso de la foto. Falsos positivos en
 * casi cualquier pieza con fotografia.
 *
 * Por que no se resuelve solo con codigo: se probo separar zonas planas
 * (diseno) de zonas con textura (foto). Descarta la mayor parte de la foto,
 * pero las superficies lisas de una fotografia (una pared, un piso de madera)
 * pasan como diseno y el falso positivo sigue.
 *
 * La solucion: la medicion sigue siendo deterministica (que colores hay y en
 * que proporcion), y el modelo de vision, que ya esta mirando la pieza, dice
 * de donde viene cada color: de la fotografia o del diseno. Solo se excluye
 * un color si el modelo lo atribuye a la fotografia con confianza alta. Ante
 * la duda, el color se sigue midiendo: el error queda del lado de reportar de
 * mas, nunca de aprobar de mas.
 *
 * Nunca se excluye un color que coincide con uno PROHIBIDO de la paleta: un
 * color vetado no se vuelve aceptable por aparecer en una foto.
 */
final class AtribucionDeColores
{
    public const CONFIANZA_MINIMA = 0.7;

    /** Por debajo de esta superficie de diseno medible, la paleta no se puede juzgar. */
    public const SUPERFICIE_MINIMA = 0.05;

    /**
     * Colores extraidos que el modelo debe atribuir. Vacio si ninguna regla
     * determinista de paleta o tipografia los va a usar.
     *
     * @return array<int, array{hex: string, share: float}>
     */
    public static function colores(Asset $asset, ResolvedRuleSet $resolved): array
    {
        $usa = $resolved->rules->contains(static fn (Rule $r): bool => $r->type === RuleType::Deterministic
            && in_array($r->category, [RuleCategory::Palette, RuleCategory::Typography], true));

        if (! $usa) {
            return [];
        }

        return collect((array) ($asset->extracted_palette ?? []))
            ->filter(static fn ($c): bool => is_array($c) && filled($c['hex'] ?? null))
            ->map(static fn (array $c): array => ['hex' => strtoupper((string) $c['hex']), 'share' => (float) ($c['share'] ?? 0)])
            ->unique('hex')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{hex: string, share: float}>  $colores
     * @return array<string, mixed>
     */
    public static function esquema(array $colores): array
    {
        return [
            'type' => 'array',
            'description' => 'Un elemento por cada color listado en ORIGEN DE COLORES.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'hex' => ['type' => 'string', 'enum' => array_column($colores, 'hex')],
                    'origin' => [
                        'type' => 'string',
                        'enum' => ['fotografia', 'diseno', 'no_determinable'],
                        'description' => 'fotografia: el color sale de una foto (personas, piel, ropa, paredes, pisos, cielo, objetos fotografiados). diseno: fondos planos, bandas, cajas, textos, iconos o logos agregados en el diseno. Si el color aparece en ambos, diseno.',
                    ],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                ],
                'required' => ['hex', 'origin', 'confidence'],
            ],
        ];
    }

    /**
     * @param  array<int, array{hex: string, share: float}>  $colores
     */
    public static function instruccion(array $colores): string
    {
        $lista = implode(', ', array_map(
            static fn (array $c): string => sprintf('%s (%.1f%%)', $c['hex'], $c['share'] * 100),
            $colores,
        ));

        return "ORIGEN DE COLORES (obligatorio)\n"
            ."Estos son los colores dominantes medidos en la pieza: {$lista}.\n"
            ."Completa color_origins con un elemento por cada uno: indica si el color proviene de la fotografia o del diseno grafico.\n"
            ."- Si un color aparece tanto en la foto como en elementos de diseno (textos, fondos planos, bandas), marca diseno.\n"
            .'- Si no puedes distinguirlo, marca no_determinable. Ante la duda, no_determinable.';
    }

    /**
     * Colores que se excluyen de la medicion.
     *
     * @param  array<int, mixed>  $origenes  lo que respondio el modelo
     * @param  array<int, array{hex: string, share: float}>  $colores
     * @param  array<int, string>  $protegidos  hexes que coinciden con colores prohibidos
     * @return array<int, array{hex: string, share: float, confidence: float}>
     */
    public static function excluidos(array $origenes, array $colores, array $protegidos = []): array
    {
        $porHex = collect($colores)->keyBy('hex');
        $protegidos = array_map('strtoupper', $protegidos);
        $fuera = [];

        foreach ($origenes as $o) {
            if (! is_array($o)) {
                continue;
            }

            $hex = strtoupper((string) ($o['hex'] ?? ''));
            $confianza = (float) ($o['confidence'] ?? 0);

            if (($o['origin'] ?? null) !== 'fotografia'
                || $confianza < self::CONFIANZA_MINIMA
                || ! $porHex->has($hex)
                || in_array($hex, $protegidos, true)) {
                continue;
            }

            $fuera[$hex] = ['hex' => $hex, 'share' => (float) $porHex[$hex]['share'], 'confidence' => round($confianza, 2)];
        }

        return array_values($fuera);
    }

    /**
     * Hexes de la pieza que coincidieron con un color prohibido.
     *
     * @param  array<int, FindingDraft>  $findings
     * @return array<int, string>
     */
    public static function protegidos(array $findings): array
    {
        return collect($findings)
            ->filter(static fn (FindingDraft $f): bool => isset($f->evidenceData['forbidden_hex']))
            ->map(static fn (FindingDraft $f): string => strtoupper((string) ($f->evidenceData['detected_hex'] ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Vuelve a medir paleta y contraste sin los colores de la fotografia.
     *
     * Reemplaza los hallazgos de esos dos evaluadores por los nuevos y marca
     * como no determinables las reglas que se quedan sin superficie de diseno
     * que medir: sin colores no hay "cumple".
     *
     * @param  array<int, FindingDraft>  $findings
     * @param  array<int, array{hex: string, share: float, confidence: float}>  $excluidos
     * @return array<int, FindingDraft>
     */
    public static function remedir(
        Asset $asset,
        ResolvedRuleSet $resolved,
        ?string $channel,
        array $findings,
        array $excluidos,
        Coverage $coverage,
    ): array {
        if ($excluidos === []) {
            return $findings;
        }

        $fuera = array_column($excluidos, 'hex');

        $restantes = array_values(array_filter(
            (array) ($asset->extracted_palette ?? []),
            static fn ($c): bool => is_array($c) && ! in_array(strtoupper((string) ($c['hex'] ?? '')), $fuera, true),
        ));

        // Copia en memoria: la paleta guardada de la pieza no se toca.
        $copia = $asset->replicate();
        $copia->extracted_palette = $restantes;

        $conservados = array_values(array_filter(
            $findings,
            static fn (FindingDraft $f): bool => ! self::esDePaletaOContraste($f),
        ));

        $nuevos = (new DeterministicEngine([new PaletteEvaluator, new ContrastEvaluator]))
            ->run($copia, $resolved, $channel, new Coverage)['findings'];

        $superficie = array_sum(array_map(static fn (array $c): float => (float) ($c['share'] ?? 0), $restantes));

        foreach ($resolved->rules as $regla) {
            if ($regla->type !== RuleType::Deterministic) {
                continue;
            }

            if ($regla->category === RuleCategory::Palette && $superficie < self::SUPERFICIE_MINIMA) {
                $coverage->mark($regla->code, RuleOutcome::NotDeterminable, 'AtribucionDeColores',
                    'Casi toda la pieza es fotografia: no queda superficie de diseno para medir la paleta.');
            }

            if ($regla->category === RuleCategory::Typography && count($restantes) < 2) {
                $coverage->mark($regla->code, RuleOutcome::NotDeterminable, 'AtribucionDeColores',
                    'Sin al menos dos colores de diseno no se puede medir el contraste.');
            }
        }

        return array_merge($conservados, $nuevos);
    }

    private static function esDePaletaOContraste(FindingDraft $f): bool
    {
        if ($f->origin !== FindingOrigin::Deterministic) {
            return false;
        }

        return $f->category === RuleCategory::Palette
            || ($f->category === RuleCategory::Typography && array_key_exists('contrast_ratio', $f->evidenceData));
    }
}
