<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/**
 * La lista de clientes de una agencia es informacion comercial sensible.
 *
 * Client no usa BelongsToBrand, asi que no tiene scope global: sin esta
 * politica y sin el filtro de getEloquentQuery en el Resource, cualquier
 * usuario veia la cartera completa.
 */
class ClientPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->esGlobal($user)
            || $user->accessibleClientIds()->isNotEmpty();
    }

    public function view(User $user, Client $client): bool
    {
        return $this->alcanzaCliente($user, $client->id);
    }

    public function create(User $user): bool
    {
        return $this->esAdminGlobal($user);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->puede($user, 'client.manage')
            && $this->alcanzaCliente($user, $client->id);
    }

    /**
     * Borrar un cliente arrastra marcas, piezas y todo su historial de
     * validaciones. Se desactiva, no se borra.
     */
    public function delete(User $user, $model): bool
    {
        return false;
    }
}
