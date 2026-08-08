<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\RuleSetStatus;
use App\Models\PromptTemplate;

/**
 * Garantiza una sola instruccion publicada por clave y alcance.
 *
 * Alcance es la terna clave + cliente + marca. Publicar una instruccion de
 * marca no toca la del cliente, y publicar una de cliente no toca la general:
 * cada nivel sigue sirviendo a quien no tiene nada mas especifico.
 *
 * Vive en un observer y no en el boton Publicar para que la invariante se
 * cumpla venga de donde venga el cambio: un seeder, un script o una edicion
 * directa. Ya nos paso con los conjuntos de reglas, donde quedaron dos
 * versiones activas y nadie se entero hasta que el resultado no cuadro.
 */
class PromptTemplateObserver
{
    public function saved(PromptTemplate $template): void
    {
        if ($template->status !== RuleSetStatus::Published) {
            return;
        }

        if (! $template->wasRecentlyCreated && ! $template->wasChanged('status')) {
            return;
        }

        PromptTemplate::query()
            ->where('key', $template->key)
            ->mismoAlcance($template->client_id, $template->brand_id)
            ->where('id', '!=', $template->id)
            ->where('status', RuleSetStatus::Published->value)
            ->update(['status' => RuleSetStatus::Retired->value]);
    }
}
