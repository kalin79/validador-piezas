<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Models\RuleSet;
use App\Models\User;

/**
 * Las reglas son el know-how del cliente: su manual de marca traducido a
 * criterios evaluables. Es lo que menos puede cruzarse entre clientes.
 *
 * El dueno es polimorfico manual (owner_type + owner_id), asi que el alcance
 * se resuelve segun el tipo: contra los clientes accesibles o contra las
 * marcas accesibles.
 */
class RuleSetPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'knowledge.view');
    }

    public function view(User $user, RuleSet $ruleSet): bool
    {
        return $this->puede($user, 'knowledge.view')
            && $this->alcanzaConjunto($user, $ruleSet);
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'knowledge.edit');
    }

    /**
     * Un conjunto publicado es inmutable: para cambiarlo se crea una version.
     *
     * Esto ya lo aplicaba la UI ocultando botones. Aqui queda como regla de
     * autorizacion, que es lo que sigue valiendo cuando alguien llega por otra
     * via.
     */
    public function update(User $user, RuleSet $ruleSet): bool
    {
        if ($ruleSet->status !== RuleSetStatus::Draft) {
            return false;
        }

        return $this->puede($user, 'knowledge.edit')
            && $this->alcanzaConjunto($user, $ruleSet);
    }

    /**
     * Solo se borran borradores, y nunca algo publicado o retirado.
     *
     * Un conjunto publicado esta referenciado por validaciones historicas. Con
     * SoftDeletes el borrado no dispara el restrictOnDelete de la migracion:
     * la relacion devuelve null y el historial deja de mostrar con que reglas
     * se juzgo la pieza. Silenciosamente, que es lo peor.
     */
    public function delete(User $user, $model): bool
    {
        if (! $model instanceof RuleSet || $model->status !== RuleSetStatus::Draft) {
            return false;
        }

        return $this->puede($user, 'knowledge.edit')
            && $this->alcanzaConjunto($user, $model);
    }

    /**
     * Publicar un conjunto de nivel cliente afecta a todas sus marcas, asi que
     * exige un permiso aparte: knowledge.publish_client, que solo tiene
     * client_admin.
     */
    public function publish(User $user, RuleSet $ruleSet): bool
    {
        if ($ruleSet->status !== RuleSetStatus::Draft) {
            return false;
        }

        if (! $this->alcanzaConjunto($user, $ruleSet)) {
            return false;
        }

        return $ruleSet->owner_type === RuleSetOwnerType::Client
            ? $this->puede($user, 'knowledge.publish_client')
            : $this->puede($user, 'knowledge.publish');
    }

    private function alcanzaConjunto(User $user, RuleSet $ruleSet): bool
    {
        return $ruleSet->owner_type === RuleSetOwnerType::Client
            ? $this->alcanzaCliente($user, $ruleSet->owner_id)
            : $this->alcanzaMarca($user, $ruleSet->owner_id);
    }
}
