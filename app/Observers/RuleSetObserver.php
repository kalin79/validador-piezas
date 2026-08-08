<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\RuleSetStatus;
use App\Models\RuleSet;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza que solo exista una version publicada por dueno.
 *
 * La regla vive aqui y no en la accion del panel a proposito: un conjunto se
 * puede publicar desde el boton, editando el formulario, desde un seeder o
 * desde tinker. Si la logica estuviera en la interfaz, cada una de esas vias
 * dejaria dos versiones vigentes a la vez, y "publicado" dejaria de significar
 * "vigente".
 */
class RuleSetObserver
{
    public function saved(RuleSet $ruleSet): void
    {
        if ($ruleSet->status !== RuleSetStatus::Published) {
            return;
        }

        // Solo actua cuando la publicacion es nueva, para no repetir el retiro
        // en cada guardado de un conjunto que ya estaba publicado.
        if (! $ruleSet->wasRecentlyCreated && ! $ruleSet->wasChanged('status')) {
            return;
        }

        $this->retirarAnteriores($ruleSet);
    }

    public function created(RuleSet $ruleSet): void
    {
        if ($ruleSet->status === RuleSetStatus::Published) {
            $this->retirarAnteriores($ruleSet);
        }
    }

    private function retirarAnteriores(RuleSet $ruleSet): int
    {
        return DB::transaction(fn (): int => RuleSet::query()
            ->where('owner_type', $ruleSet->owner_type->value)
            ->where('owner_id', $ruleSet->owner_id)
            ->whereKeyNot($ruleSet->getKey())
            ->where('status', RuleSetStatus::Published->value)
            ->update([
                'status' => RuleSetStatus::Retired->value,
                'updated_at' => now(),
            ]));
    }
}
