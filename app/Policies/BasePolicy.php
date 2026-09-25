<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Base comun de las politicas de autorizacion.
 *
 * El modelo de acceso ya existia en User: accessibleBrandIds() y
 * accessibleClientIds() derivan del pivote de equipos y estan cacheados diez
 * minutos. Lo que faltaba era usarlo. Filament sin Policy autoriza todo por
 * omision, asi que el panel entregaba la cartera completa de clientes a
 * cualquier usuario autenticado.
 *
 * Dos ejes, y ambos tienen que dar verdadero:
 *
 *   1. PERMISO   -> que puede hacer el rol. Viene de Spatie.
 *   2. ALCANCE   -> sobre que registros. Viene de los equipos del usuario.
 *
 * Un client_admin tiene 'knowledge.publish' pero solo sobre las marcas de su
 * cliente. Separar los dos ejes evita el error clasico de confundir "puede
 * publicar" con "puede publicar esto".
 */
abstract class BasePolicy
{
    /**
     * Los roles globales ven todo. Es deliberado y son solo dos:
     *
     *   super_admin -> administra la instalacion
     *   auditor     -> observador puro, sin permisos de escritura
     *
     * El auditor pasa por aqui pero sus acciones de escritura las corta el
     * chequeo de permiso, no el de alcance.
     */
    protected function esGlobal(User $user): bool
    {
        return $user->hasGlobalAccess();
    }

    /**
     * Alcance global NO es privilegio global.
     *
     * hasGlobalAccess() devuelve verdadero para super_admin y para auditor,
     * porque ambos VEN todas las marcas. Pero el auditor es un observador puro:
     * su rol no tiene un solo permiso de escritura.
     *
     * Usar esGlobal() para autorizar escrituras le abria al auditor la
     * asignacion de roles, la creacion de equipos y la restauracion de
     * registros borrados. Lo detecto la prueba de aislamiento.
     *
     * Regla: esGlobal() para leer, esAdminGlobal() para escribir lo que no
     * tiene dueno concreto contra el cual medir alcance.
     */
    protected function esAdminGlobal(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    protected function puede(User $user, string $permiso): bool
    {
        return $user->hasPermissionTo($permiso);
    }

    /**
     * @param  int|string|null  $clientId
     */
    protected function alcanzaCliente(User $user, $clientId): bool
    {
        if ($this->esGlobal($user)) {
            return true;
        }

        if ($clientId === null) {
            return false;
        }

        return $user->accessibleClientIds()->contains((int) $clientId);
    }

    /**
     * @param  int|string|null  $brandId
     */
    /**
     * Acceso al cliente ENTERO, no solo a alguna de sus marcas.
     *
     * Lo exigen las decisiones que afectan a todas las marcas del cliente:
     * reglas corporativas e instrucciones del modelo de nivel cliente. Con
     * alcanzaCliente() bastaba ver una marca para gobernar todas las demas.
     */
    protected function alcanzaClienteCompleto(User $user, $clientId): bool
    {
        if ($this->esGlobal($user)) {
            return true;
        }

        if ($clientId === null) {
            return false;
        }

        return $user->fullAccessClientIds()->contains((int) $clientId);
    }

    protected function alcanzaMarca(User $user, $brandId): bool
    {
        if ($this->esGlobal($user)) {
            return true;
        }

        if ($brandId === null) {
            return false;
        }

        return $user->canAccessBrand((int) $brandId);
    }

    /**
     * Nada se borra de verdad en este sistema.
     *
     * Un validador de piezas se vende por su trazabilidad: si un registro se
     * puede borrar, la promesa de "podras responder por que se aprobo esta
     * pieza en marzo" deja de ser cierta. Las politicas concretas que necesiten
     * permitir borrado de borradores lo sobrescriben explicitamente.
     */
    public function delete(User $user, $model): bool
    {
        return false;
    }

    public function restore(User $user, $model): bool
    {
        return $this->esAdminGlobal($user);
    }

    public function forceDelete(User $user, $model): bool
    {
        return false;
    }
}
