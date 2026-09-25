<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un token vale mientras su usuario siga activo.
 *
 * Antes solo el login revisaba is_active: desactivar a un disenador externo
 * no cortaba el token que ya tenia en el plugin, que seguia validando (y
 * gastando) indefinidamente.
 */
final class EnsureApiUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null || ! $usuario->is_active) {
            return response()->json([
                'error' => 'usuario_inactivo',
                'message' => 'Tu usuario esta desactivado. Contacta al administrador.',
            ], 403);
        }

        return $next($request);
    }
}
