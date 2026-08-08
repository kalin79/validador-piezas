<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Los equipos son la unica fuente real de aislamiento entre clientes.
 *
 * accessibleBrandIds() se calcula a partir de team_client_access y
 * team_brand_access. Quien pueda editar equipos puede darse acceso a cualquier
 * cliente, asi que esta politica es tan critica como la de usuarios.
 *
 * Por eso la creacion y el borrado quedan reservados a roles globales: un
 * client_admin puede administrar los equipos de su cliente, pero no crear uno
 * nuevo que apunte a otro.
 */
class TeamPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'team.manage');
    }

    public function view(User $user, Team $team): bool
    {
        return $this->puede($user, 'team.manage')
            && $this->alcanzaEquipo($user, $team);
    }

    /**
     * Solo roles globales crean equipos.
     *
     * Un equipo nuevo puede apuntar a cualquier cliente, y el formulario se
     * llena antes de que exista el registro: no hay alcance contra el cual
     * validar en ese momento.
     */
    public function create(User $user): bool
    {
        return $this->esAdminGlobal($user);
    }

    public function update(User $user, Team $team): bool
    {
        return $this->puede($user, 'team.manage')
            && $this->alcanzaEquipo($user, $team);
    }

    /**
     * Borrar un equipo revoca accesos en cascada y en silencio.
     */
    public function delete(User $user, $model): bool
    {
        return false;
    }

    /**
     * Un equipo esta al alcance si todos los clientes y marcas que concede
     * estan dentro del alcance de quien lo edita.
     *
     * Se exige que TODOS coincidan, no solo uno: si bastara la interseccion,
     * un client_admin podria editar un equipo compartido y, de paso, quitarle
     * o darle acceso a un cliente que no le corresponde.
     */
    private function alcanzaEquipo(User $user, Team $team): bool
    {
        if ($this->esGlobal($user)) {
            return true;
        }

        $clientesDelEquipo = $team->clients()->pluck('clients.id');
        $marcasDelEquipo = $team->brands()->pluck('brands.id');

        if ($clientesDelEquipo->isEmpty() && $marcasDelEquipo->isEmpty()) {
            return false;
        }

        $clientesPropios = $user->accessibleClientIds();
        $marcasPropias = $user->accessibleBrandIds();

        return $clientesDelEquipo->diff($clientesPropios)->isEmpty()
            && $marcasDelEquipo->diff($marcasPropias)->isEmpty();
    }
}
