<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->esGlobal($user)
            || $user->accessibleBrandIds()->isNotEmpty();
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->alcanzaMarca($user, $brand->id);
    }

    /**
     * Crear una marca implica elegirle cliente, y el formulario se llena antes
     * de que exista el registro. Se reserva a quien administra el cliente.
     */
    public function create(User $user): bool
    {
        return $this->puede($user, 'brand.manage');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->puede($user, 'brand.manage')
            && $this->alcanzaMarca($user, $brand->id);
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
