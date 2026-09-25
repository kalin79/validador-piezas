<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BrandAsset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sirve piezas y activos de marca desde el disco privado.
 *
 * Antes vivian en el disco public y se servian por /storage sin sesion: una
 * URL filtrada (o guardada por alguien a quien luego se le quito el acceso)
 * daba la pieza de una campana sin publicar a cualquiera. Ahora cada descarga
 * pasa por la politica del registro.
 *
 * Las cabeceras impiden que el navegador interprete el archivo como otra cosa
 * que una imagen, aunque alguien haya logrado subir contenido disfrazado.
 */
final class ArchivoController extends Controller
{
    private const CABECERAS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        'Cache-Control' => 'private, max-age=300',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    private const TIPOS_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];

    public function pieza(Asset $asset): Response
    {
        Gate::authorize('view', $asset);

        return $this->servir($asset->storage_disk, $asset->storage_path, $asset->mime_type);
    }

    public function activoMarca(BrandAsset $brandAsset): Response
    {
        Gate::authorize('view', $brandAsset);

        return $this->servir($brandAsset->storage_disk, $brandAsset->storage_path, $brandAsset->mime_type);
    }

    private function servir(?string $disco, ?string $ruta, ?string $mime): Response
    {
        $storage = Storage::disk($disco ?: (string) config('filesystems.piezas_disk', 'local'));

        abort_if(blank($ruta) || ! $storage->exists($ruta), 404);

        // Solo se sirven imagenes rasterizadas. Cualquier otro tipo (un SVG
        // antiguo, por ejemplo) se descarga como adjunto en vez de mostrarse.
        $tipo = in_array($mime, self::TIPOS_PERMITIDOS, true) ? $mime : 'application/octet-stream';
        $disposicion = $tipo === 'application/octet-stream' ? 'attachment' : 'inline';

        return $storage->response($ruta, basename((string) $ruta), array_merge(self::CABECERAS, [
            'Content-Type' => $tipo,
        ]), $disposicion);
    }
}
