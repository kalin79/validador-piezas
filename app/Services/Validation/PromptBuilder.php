<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Models\Asset;
use App\Models\BrandAsset;
use App\Models\PromptTemplate;
use App\Services\ResolvedRuleSet;
use Illuminate\Support\Collection;

/**
 * Ensambla el prompt desde la plantilla versionada en base de datos.
 *
 * Nunca se construye el prompt con cadenas literales en el codigo: la
 * ejecucion guarda el prompt_template_id, y eso solo tiene sentido si el
 * texto vive en un registro que puede consultarse despues.
 */
final class PromptBuilder
{
    /**
     * @param  array<int, FindingDraft>  $deterministicFindings
     * @return array{system: string, user: string, template: PromptTemplate}
     */
    public function build(
        Asset $asset,
        ResolvedRuleSet $resolved,
        ?string $channel,
        array $deterministicFindings,
    ): array {
        // Se pasa tambien el cliente para que la cascada pueda encontrar una
        // instruccion escrita para todas las marcas de ese cliente, no solo la
        // de la marca o la general.
        $template = PromptTemplate::resolveFor(
            (string) config('ai.prompt_key', 'piece_validation'),
            $asset->brand_id,
            $asset->brand?->client_id,
        );

        if ($template === null) {
            throw new \RuntimeException(
                'No hay plantilla de prompt publicada. Ejecuta: php artisan db:seed --class=PromptTemplateSeeder'
            );
        }

        $brand = $asset->brand;
        $submission = $asset->submission;

        $reemplazos = [
            '{{client_name}}' => $brand?->clientName() ?? '—',
            '{{brand_name}}' => $brand?->name ?? '—',
            '{{channel}}' => $this->canal($channel),
            '{{campaign}}' => $submission?->campaign ?: 'no declarada',
            '{{product}}' => $submission?->product ?: 'no declarado',
            '{{objective}}' => $submission?->objective ?: 'no declarado',
            '{{width}}' => (string) ($asset->width ?? '?'),
            '{{height}}' => (string) ($asset->height ?? '?'),
            '{{rules}}' => $this->reglas($resolved),
            '{{brand_assets}}' => $this->activos($asset, $channel),
            '{{deterministic_findings}}' => $this->deterministas($deterministicFindings),
            '{{extracted_palette}}' => $this->paleta($asset),
        ];

        return [
            'system' => $template->system_prompt,
            'user' => strtr($template->user_prompt_template, $reemplazos),
            'template' => $template,
        ];
    }

    private function canal(?string $channel): string
    {
        if ($channel === null) {
            return 'no declarado';
        }

        $preset = config("channels.presets.{$channel}");

        return $preset === null
            ? $channel
            : sprintf('%s (%s, %s)', $preset['label'], $channel, $preset['ratio_label']);
    }

    /**
     * Solo se envian las reglas de juicio: las deterministas ya las evaluo el
     * codigo y repetirlas invita al modelo a contradecir una medicion exacta.
     */
    private function reglas(ResolvedRuleSet $resolved): string
    {
        $reglas = $resolved->judgmentRules();

        if ($reglas->isEmpty()) {
            return 'No hay reglas de juicio en este conjunto.';
        }

        return $reglas->map(function ($regla): string {
            $linea = sprintf(
                "[%s] %s — severidad %s, categoria %s\n%s",
                $regla->code,
                $regla->title,
                $regla->severity->value,
                $regla->category->value,
                $regla->statement,
            );

            if (filled($regla->positive_examples)) {
                $linea .= "\nCumple: ".implode(' | ', (array) $regla->positive_examples);
            }

            if (filled($regla->negative_examples)) {
                $linea .= "\nNo cumple: ".implode(' | ', (array) $regla->negative_examples);
            }

            return $linea;
        })->implode("\n\n");
    }

    private function activos(Asset $asset, ?string $channel): string
    {
        $activos = BrandAsset::query()
            ->where('brand_id', $asset->brand_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (BrandAsset $a): bool => $a->appliesToChannel($channel));

        if ($activos->isEmpty()) {
            return 'No hay activos de marca cargados para esta marca.';
        }

        return $activos->map(function (BrandAsset $a): string {
            $partes = [sprintf('%s (%s)', $a->name, $a->type->label())];

            if ($a->is_required) {
                $partes[] = 'presencia obligatoria';
            }

            if ($a->min_width_percent !== null) {
                $partes[] = sprintf('debe ocupar al menos el %s%% del ancho', $a->min_width_percent);
            }

            if (filled($a->allowed_positions)) {
                $partes[] = 'posiciones permitidas: '.implode(', ', (array) $a->allowed_positions);
            }

            return '- '.implode('; ', $partes);
        })->implode("\n");
    }

    /**
     * @param  array<int, FindingDraft>  $findings
     */
    private function deterministas(array $findings): string
    {
        if ($findings === []) {
            return 'El analisis por codigo no encontro incumplimientos.';
        }

        return collect($findings)->map(fn (FindingDraft $f): string => sprintf(
            '- [%s] %s: %s%s',
            $f->severity->value,
            $f->category->value,
            $f->description,
            $f->evidence !== null ? ' ('.$f->evidence.')' : '',
        ))->implode("\n");
    }

    private function paleta(Asset $asset): string
    {
        $paleta = $asset->extracted_palette ?? [];

        if ($paleta === []) {
            return 'No se pudo extraer la paleta.';
        }

        return collect($paleta)
            ->take(6)
            ->map(fn (array $c): string => sprintf('%s (%s%%)', $c['hex'], $c['percent']))
            ->implode(', ');
    }
}
