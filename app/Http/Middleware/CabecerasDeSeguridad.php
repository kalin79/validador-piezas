<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas web.
 *
 * - nosniff: el navegador no reinterpreta un archivo como otro tipo.
 * - SAMEORIGIN: el panel no se puede incrustar en otro sitio (clickjacking).
 * - Referrer-Policy: las URLs internas no se filtran a sitios externos.
 * - noindex: es una herramienta interna; ni buscadores ni rastreadores de IA
 *   deben indexar el login ni la API.
 * - HSTS solo en produccion y sobre HTTPS, para no bloquear el desarrollo local.
 */
final class CabecerasDeSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $respuesta->headers->set('X-Content-Type-Options', 'nosniff', false);
        $respuesta->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $respuesta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow', false);
        $respuesta->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()', false);

        if (app()->isProduction() && $request->isSecure()) {
            $respuesta->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        return $respuesta;
    }
}
