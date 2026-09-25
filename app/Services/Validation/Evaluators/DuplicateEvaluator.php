<?php

declare(strict_types=1);

namespace App\Services\Validation\Evaluators;

use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Models\Asset;
use App\Services\Validation\FindingDraft;
use Illuminate\Support\Collection;

/**
 * Detecta piezas identicas ya cargadas para la misma marca.
 *
 * No es un error: resubir una pieza en otra campana es legitimo. Se reporta
 * como informativo para que el revisor sepa que ya existe un veredicto previo
 * sobre exactamente el mismo archivo, y pueda compararlo.
 */
final class DuplicateEvaluator implements Evaluator
{
    public function handles(): array
    {
        return [];
    }

    public function evaluate(Asset $asset, Collection $rules, ?string $channel): array
    {
        $previos = Asset::query()
            ->where('brand_id', $asset->brand_id)
            ->where('file_hash', $asset->file_hash)
            ->where('id', '!=', $asset->id)
            ->with('submission')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        if ($previos->isEmpty()) {
            return [];
        }

        $primero = $previos->first();

        return [new FindingDraft(
            category: RuleCategory::Composition,
            severity: Severity::Info,
            description: sprintf(
                'Esta pieza ya se habia cargado %s para esta marca. La primera coincidencia es del %s.',
                $previos->count() === 1 ? 'una vez' : $previos->count().' veces',
                \App\Support\Fecha::local($primero->created_at)?->format('d/m/Y') ?? 'fecha desconocida',
            ),
            evidence: 'SHA-256 '.substr($asset->file_hash, 0, 16).'...',
            evidenceData: [
                'file_hash' => $asset->file_hash,
                'duplicate_count' => $previos->count(),
                'duplicates' => $previos->map(fn (Asset $a): array => [
                    'public_id' => $a->public_id,
                    'filename' => $a->original_filename,
                    'campaign' => $a->submission?->campaign,
                    'uploaded_at' => $a->created_at?->toDateTimeString(),
                ])->all(),
            ],
            suggestion: 'Revisa el veredicto anterior antes de volver a procesarla.',
        )];
    }
}
