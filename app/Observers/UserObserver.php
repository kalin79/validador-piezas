<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function saved(User $user): void
    {
        $user->forgetAccessCache();

        // Desactivar a alguien tiene que cortar su acceso por la API tambien.
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $user->tokens()->delete();
        }
    }

    public function created(User $user): void
    {
        app(\App\Services\AuditLogger::class)->log('user.created', $user, newValues: ['email' => $user->email, 'name' => $user->name]);
    }

    /**
     * "updated" y no "saved": wasRecentlyCreated sigue en true durante toda la
     * peticion, y un update posterior sobre la misma instancia se confundia
     * con la creacion.
     */
    public function updated(User $user): void
    {
        $bitacora = app(\App\Services\AuditLogger::class);

        if ($user->wasChanged('is_active')) {
            $bitacora->log($user->is_active ? 'user.activated' : 'user.deactivated', $user);
        }

        if ($user->wasChanged('password')) {
            // Se registra que cambio, nunca el valor ni el hash.
            $bitacora->log('user.password_changed', $user);
        }

        if ($user->wasChanged('email')) {
            $bitacora->log('user.email_changed', $user, oldValues: ['email' => $user->getOriginal('email')], newValues: ['email' => $user->email]);
        }
    }
}
