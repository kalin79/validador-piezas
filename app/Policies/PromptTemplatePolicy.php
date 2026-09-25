<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RuleSetStatus;
use App\Models\PromptTemplate;
use App\Models\User;

/**
 * Las instrucciones del modelo determinan como se juzga cada pieza. Cambiarlas
 * cambia el resultado de todas las validaciones futuras.
 *
 * El alcance se resuelve en cascada: marca -> cliente -> general. Una plantilla
 * general (client_id y brand_id nulos) afecta a todos los clientes, asi que
 * solo la tocan roles globales.
 */
class PromptTemplatePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'knowledge.view');
    }

    public function view(User $user, PromptTemplate $template): bool
    {
        return $this->puede($user, 'knowledge.view')
            && $this->alcanzaPlantilla($user, $template);
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'knowledge.edit');
    }

    public function update(User $user, PromptTemplate $template): bool
    {
        if ($template->status !== RuleSetStatus::Draft) {
            return false;
        }

        return $this->puede($user, 'knowledge.edit')
            && $this->alcanzaPlantilla($user, $template);
    }

    public function delete(User $user, $model): bool
    {
        if (! $model instanceof PromptTemplate || $model->status !== RuleSetStatus::Draft) {
            return false;
        }

        return $this->puede($user, 'knowledge.edit')
            && $this->alcanzaPlantilla($user, $model);
    }

    public function publish(User $user, PromptTemplate $template): bool
    {
        if ($template->status !== RuleSetStatus::Draft) {
            return false;
        }

        if (! $this->alcanzaPlantilla($user, $template)) {
            return false;
        }

        return match (true) {
            $template->client_id === null => $this->esAdminGlobal($user),
            // Nivel cliente: rige para todas sus marcas. Mismo permiso que las
            // reglas corporativas.
            $template->brand_id === null => $this->puede($user, 'knowledge.publish_client'),
            default => $this->puede($user, 'knowledge.publish'),
        };
    }

    private function alcanzaPlantilla(User $user, PromptTemplate $template): bool
    {
        if ($this->esGlobal($user)) {
            return true;
        }

        // Plantilla general: afecta a todos los clientes. Nadie con alcance
        // parcial deberia verla ni editarla.
        if ($template->client_id === null) {
            return false;
        }

        if ($template->brand_id !== null) {
            return $this->alcanzaMarca($user, $template->brand_id);
        }

        return $this->alcanzaClienteCompleto($user, $template->client_id);
    }
}
