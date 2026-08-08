<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Acceso con correo y contrasena para clientes externos.
 *
 * El plugin de Figma no deberia obligar a un disenador a entrar al panel,
 * generar un token y pegarlo. Entra con las credenciales que ya tiene y el
 * token se emite y se guarda solo.
 *
 * Por dentro sigue siendo Sanctum: lo que cambia es de donde sale el token.
 * Eso conserva lo importante —se puede revocar desde el panel sin cambiarle la
 * contrasena a nadie— y quita la friccion de copiarlo a mano.
 */
final class AuthController
{
    /**
     * Intentos fallidos antes de bloquear, y por cuanto tiempo.
     *
     * Un endpoint de acceso sin limite es una invitacion a probar contrasenas
     * en bucle. Se cuenta por correo mas IP y no solo por IP: si se contara
     * solo por IP, toda una agencia detras del mismo router se bloquearia
     * entre si al tercer dedazo.
     */
    private const INTENTOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);

        $llave = 'acceso:'.Str::lower($datos['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($llave, self::INTENTOS)) {
            $segundos = RateLimiter::availableIn($llave);

            return response()->json([
                'error' => 'demasiados_intentos',
                'message' => "Demasiados intentos. Vuelve a probar en {$segundos} segundos.",
                'retry_after' => $segundos,
            ], 429);
        }

        $usuario = User::query()->where('email', $datos['email'])->first();

        /*
         * Un solo mensaje para "no existe" y "contrasena incorrecta".
         *
         * Distinguirlos permite averiguar que correos estan registrados
         * probandolos uno por uno, y en este sistema eso revela quien trabaja
         * para que agencia.
         *
         * Hash::check se ejecuta contra un hash ficticio cuando el usuario no
         * existe para que el tiempo de respuesta sea parecido: si validar un
         * correo inexistente tardara la mitad, la diferencia bastaria para
         * distinguirlos igual.
         */
        $valida = $usuario !== null
            ? Hash::check($datos['password'], $usuario->password)
            : Hash::check($datos['password'], '$2y$12$'.str_repeat('x', 53));

        if (! $valida || $usuario === null) {
            RateLimiter::hit($llave, self::BLOQUEO_SEGUNDOS);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        if (! $usuario->is_active) {
            RateLimiter::hit($llave, self::BLOQUEO_SEGUNDOS);

            return response()->json([
                'error' => 'usuario_inactivo',
                'message' => 'Tu usuario esta desactivado. Contacta al administrador.',
            ], 403);
        }

        if (! $usuario->hasPermissionTo('validation.trigger')) {
            return response()->json([
                'error' => 'sin_permiso',
                'message' => 'Tu usuario no tiene permiso para validar piezas.',
            ], 403);
        }

        RateLimiter::clear($llave);

        $nombre = trim((string) ($datos['device_name'] ?? '')) ?: 'Plugin de Figma';

        /*
         * Un token por dispositivo, no uno por sesion.
         *
         * Sin esto, cada vez que el disenador entra se acumula un token mas y
         * en un mes tiene treinta, todos validos y ninguno identificable. Se
         * revocan los anteriores del mismo nombre antes de emitir el nuevo:
         * entrar desde el mismo equipo reemplaza la sesion, entrar desde otro
         * la suma.
         */
        $usuario->tokens()->where('name', $nombre)->delete();

        $token = $usuario->createToken($nombre);

        $usuario->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'brands' => $usuario->accessibleBrandCount(),
                'device_name' => $nombre,
            ],
        ]);
    }

    /**
     * Cierra la sesion revocando unicamente el token con el que se llamo.
     *
     * Salir en un equipo no debe desconectar los demas: alguien que trabaja en
     * la oficina y en casa perderia la sesion del otro sitio sin entender por
     * que.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['message' => 'Sesion cerrada.']]);
    }
}
