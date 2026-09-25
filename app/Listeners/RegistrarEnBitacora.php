<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Eventos del sistema que tienen que quedar en la bitacora de auditoria.
 *
 * Antes AuditLogger existia pero nadie lo llamaba: la tabla audit_logs estaba
 * vacia y no habia forma de responder quien dio un rol o quien entro al panel.
 */
final class RegistrarEnBitacora
{
    public function __construct(private AuditLogger $bitacora) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'fallido',
            Logout::class => 'logout',
            RoleAttachedEvent::class => 'roles',
            RoleDetachedEvent::class => 'roles',
        ];
    }

    public function login(Login $e): void
    {
        if ($e->user instanceof User) {
            // last_login_at solo se actualizaba desde la API. Se escribe sin
            // disparar observadores: no es un cambio del usuario.
            $e->user->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        $this->bitacora->log('auth.login', $e->user instanceof User ? $e->user : null, newValues: ['guard' => $e->guard]);
    }

    public function fallido(Failed $e): void
    {
        // Solo el correo intentado. Nunca la contrasena.
        $this->bitacora->log('auth.failed', null, newValues: [
            'guard' => $e->guard,
            'email' => is_string($e->credentials['email'] ?? null) ? mb_substr($e->credentials['email'], 0, 191) : null,
        ]);
    }

    public function logout(Logout $e): void
    {
        $this->bitacora->log('auth.logout', $e->user instanceof User ? $e->user : null, newValues: ['guard' => $e->guard]);
    }

    public function roles(RoleAttachedEvent|RoleDetachedEvent $e): void
    {
        if (! $e->model instanceof User) {
            return;
        }

        $this->bitacora->log('user.roles_changed', $e->model, newValues: [
            'cambio' => $e instanceof RoleAttachedEvent ? 'asignado' : 'retirado',
            'roles_actuales' => $e->model->roles()->pluck('name')->all(),
        ]);
    }
}
