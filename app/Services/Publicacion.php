<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleSetStatus;
use App\Models\PromptTemplate;
use App\Models\RuleSet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publicar una version y retirar las anteriores del mismo dueno, sin carreras.
 *
 * Antes el retiro vivia en un observer que corria despues de guardar y fuera
 * de cualquier bloqueo. Con dos publicaciones simultaneas del mismo dueno,
 * cada una retiraba a la otra y podia quedar NINGUNA vigente: las validaciones
 * siguientes corrian sin las reglas de marca y aprobaban de mas.
 *
 * Aqui todo ocurre en una transaccion que bloquea (SELECT ... FOR UPDATE)
 * todas las versiones del mismo dueno. La segunda publicacion espera a la
 * primera, la ve publicada y la retira: siempre queda exactamente una.
 *
 * Los observers siguen existiendo como red para seeders y scripts, que no
 * compiten entre si.
 */
final class Publicacion
{
    /**
     * @return int versiones anteriores retiradas
     */
    public function publicarConjunto(RuleSet $ruleSet, ?int $userId): int
    {
        return DB::transaction(function () use ($ruleSet, $userId): int {
            $hermanos = RuleSet::query()
                ->where('owner_type', $ruleSet->owner_type->value)
                ->where('owner_id', $ruleSet->owner_id)
                ->lockForUpdate()
                ->get();

            $actual = $hermanos->firstWhere('id', $ruleSet->id)
                ?? throw new RuntimeException('El conjunto ya no existe.');

            if ($actual->status !== RuleSetStatus::Draft) {
                throw new RuntimeException('Este conjunto ya no es un borrador: otra persona lo publico o retiro.');
            }

            if ($actual->rules()->count() === 0) {
                throw new RuntimeException('Un conjunto vacio publicado aprobaria todo por omision. Agrega al menos una regla.');
            }

            $retiradas = RuleSet::query()
                ->whereIn('id', $hermanos->where('status', RuleSetStatus::Published)->pluck('id'))
                ->update(['status' => RuleSetStatus::Retired->value, 'updated_at' => now()]);

            $ruleSet->forceFill([
                'status' => RuleSetStatus::Published,
                'published_at' => now(),
                'published_by' => $userId,
            ])->save();

            app(\App\Services\AuditLogger::class)->log('rule_set.published', $ruleSet, newValues: [
                'version' => $ruleSet->version,
                'owner_type' => $ruleSet->owner_type->value,
                'owner_id' => $ruleSet->owner_id,
                'retired_ids' => $hermanos->where('status', RuleSetStatus::Published)->pluck('id')->values()->all(),
                'changelog' => $ruleSet->changelog,
            ], clientId: $ruleSet->client_id !== null ? (int) $ruleSet->client_id : null);

            return $retiradas;
        });
    }

    /**
     * @return int versiones anteriores retiradas
     */
    public function publicarPlantilla(PromptTemplate $template): int
    {
        return DB::transaction(function () use ($template): int {
            $hermanas = PromptTemplate::query()
                ->where('key', $template->key)
                ->mismoAlcance($template->client_id, $template->brand_id)
                ->lockForUpdate()
                ->get();

            $actual = $hermanas->firstWhere('id', $template->id)
                ?? throw new RuntimeException('La plantilla ya no existe.');

            if ($actual->status !== RuleSetStatus::Draft) {
                throw new RuntimeException('Esta plantilla ya no es un borrador: otra persona la publico o retiro.');
            }

            if (! is_array($actual->output_schema) || $actual->output_schema === []) {
                throw new RuntimeException('La plantilla no tiene esquema de salida: el motor no podria validar la respuesta del modelo.');
            }

            $retiradas = PromptTemplate::query()
                ->whereIn('id', $hermanas->where('status', RuleSetStatus::Published)->pluck('id'))
                ->update(['status' => RuleSetStatus::Retired->value, 'updated_at' => now()]);

            $template->forceFill([
                'status' => RuleSetStatus::Published->value,
                'published_at' => now(),
            ])->save();

            app(\App\Services\AuditLogger::class)->log('prompt_template.published', $template, newValues: [
                'key' => $template->key,
                'version' => $template->version,
                'alcance' => $template->alcance(),
                'system_prompt_sha256' => hash('sha256', (string) $template->system_prompt),
                'user_prompt_sha256' => hash('sha256', (string) $template->user_prompt_template),
                'retired_ids' => $hermanas->where('status', RuleSetStatus::Published)->pluck('id')->values()->all(),
            ]);

            return $retiradas;
        });
    }
}
