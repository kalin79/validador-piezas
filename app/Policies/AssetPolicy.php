<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

/**
 * Asset usa BelongsToBrand: el scope global ya filtra el listado. Esta
 * politica cubre el acceso por ruta directa y la accion de validar, que gasta
 * tokens y por eso tiene permiso propio.
 */
class AssetPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'validation.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->puede($user, 'validation.view')
            && $this->alcanzaMarca($user, $asset->brand_id);
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'submission.create');
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->puede($user, 'submission.create')
            && $this->alcanzaMarca($user, $asset->brand_id);
    }

    /**
     * La pieza es la evidencia. Borrarla deja las validaciones apuntando a un
     * archivo que ya no existe.
     */
    public function delete(User $user, $model): bool
    {
        return false;
    }

    public function validar(User $user, Asset $asset): bool
    {
        return $this->puede($user, 'validation.trigger')
            && $this->alcanzaMarca($user, $asset->brand_id);
    }

    /**
     * Confirmar o descartar un hallazgo alimenta las metricas de precision del
     * motor: quien revisa mal, calibra mal.
     */
    public function revisar(User $user, Asset $asset): bool
    {
        return $this->puede($user, 'review.perform')
            && $this->alcanzaMarca($user, $asset->brand_id);
    }
}
