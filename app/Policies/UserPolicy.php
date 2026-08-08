<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * La politica mas importante del sistema.
 *
 * Sin ella, cualquier usuario autenticado entraba a /admin/users, se asignaba
 * el rol super_admin y hasGlobalAccess() le abria todos los clientes. Una
 * escalada de privilegios en tres clics.
 */
class UserPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->puede($user, 'user.manage');
    }

    public function view(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return true;
        }

        if (! $this->puede($user, 'user.manage')) {
            return false;
        }

        return $this->compartenAlcance($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->puede($user, 'user.manage');
    }

    public function update(User $user, User $model): bool
    {
        if (! $this->puede($user, 'user.manage')) {
            return false;
        }

        // Un administrador de marca no puede editar a un super_admin ni a un
        // auditor: seria escalar por la puerta de atras, cambiandole la clave
        // a alguien con mas alcance que uno mismo.
        if ($model->hasGlobalAccess() && ! $this->esGlobal($user)) {
            return false;
        }

        return $user->is($model) || $this->compartenAlcance($user, $model);
    }

    /**
     * Los usuarios se desactivan con is_active, no se borran.
     *
     * Un usuario borrado deja huerfanas sus submissions y sus revisiones, y el
     * historial pierde de quien fue cada accion.
     */
    public function delete(User $user, $model): bool
    {
        return false;
    }

    /**
     * Solo un rol global asigna roles.
     *
     * El formulario consulta este gate para decidir si muestra el campo. Sin
     * esta separacion, cualquiera con user.manage podria concederse permisos
     * que su propio rol no tiene.
     */
    public function assignRoles(User $user, ?User $model = null): bool
    {
        return $this->esAdminGlobal($user);
    }

    /**
     * Solo un rol global cambia la contrasena de otro. Cada quien la suya.
     */
    public function changePassword(User $user, User $model): bool
    {
        return $user->is($model) || $this->esAdminGlobal($user);
    }

    /**
     * Dos usuarios comparten alcance si al menos una marca visible coincide.
     *
     * Se compara por marca y no por cliente porque la marca es la unidad real
     * de acceso: un equipo puede tener acceso a una sola marca de un cliente.
     */
    private function compartenAlcance(User $user, User $model): bool
    {
        if ($this->esGlobal($user)) {
            return true;
        }

        return $user->accessibleBrandIds()
            ->intersect($model->accessibleBrandIds())
            ->isNotEmpty();
    }
}
