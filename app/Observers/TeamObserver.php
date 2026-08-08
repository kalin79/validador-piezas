<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Team;

/**
 * La cache de accesos vive 10 minutos. Sin esto, quitarle un cliente a un equipo
 * dejaria a sus miembros viendo marcas que ya no les corresponden hasta que expire.
 *
 * Hay un detalle de orden que importa: Filament guarda primero el modelo y
 * despues sincroniza los pivotes de clientes y marcas. El evento saved() se
 * dispara en medio, asi que limpiar la cache ahi la vuelve a llenar con los
 * accesos VIEJOS —los pivotes todavia no cambiaron— y el usuario conserva el
 * acceso revocado durante los diez minutos siguientes.
 *
 * Se resuelve limpiando tambien despues de la peticion, cuando la
 * sincronizacion ya ocurrio. Cuesta una limpieza de mas y cierra la ventana.
 */
class TeamObserver
{
    public function saved(Team $team): void
    {
        $this->flush($team);
        $this->flushDespuesDeLaPeticion($team);
    }

    public function deleted(Team $team): void
    {
        $this->flush($team);
        $this->flushDespuesDeLaPeticion($team);
    }

    private function flush(Team $team): void
    {
        $team->users()->cursor()->each(
            fn ($user) => $user->forgetAccessCache()
        );
    }

    /**
     * Segunda limpieza, ya con los pivotes sincronizados.
     *
     * Se difiere en vez de encadenarse a un evento de pivote porque Eloquent no
     * emite eventos de modelo en las operaciones de tabla intermedia: attach,
     * detach y sync no disparan saved().
     */
    private function flushDespuesDeLaPeticion(Team $team): void
    {
        $id = $team->getKey();

        app()->terminating(function () use ($id): void {
            $equipo = Team::query()->withTrashed()->find($id);

            if ($equipo !== null) {
                $this->flush($equipo);
            }
        });
    }
}
