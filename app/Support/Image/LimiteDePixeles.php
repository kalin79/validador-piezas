<?php

declare(strict_types=1);

namespace App\Support\Image;

use RuntimeException;

/**
 * Rechaza imagenes demasiado grandes ANTES de decodificarlas.
 *
 * El limite de 20 MB es de peso comprimido. Un PNG de 20 MB puede medir
 * 40000 x 40000 px, y GD necesita unos 4 bytes por pixel para abrirlo: mas de
 * 6 GB. Eso es un error fatal por memoria, que ningun try/catch atrapa, y
 * tumba el proceso (la peticion o el worker) dejando la validacion a medias.
 *
 * getimagesize() lee solo la cabecera, asi que la comprobacion es barata.
 */
final class LimiteDePixeles
{
    public static function maximo(): int
    {
        // Sin aplicacion levantada (pruebas unitarias puras) se usa el valor
        // por defecto en vez de fallar.
        $megapixeles = app()->bound('config')
            ? (int) config('filesystems.piezas_max_megapixeles', 50)
            : 50;

        return max(1, $megapixeles) * 1_000_000;
    }

    /**
     * @throws RuntimeException si no se pueden leer las dimensiones o se excede el limite
     */
    public static function verificar(string $ruta): void
    {
        $info = @getimagesize($ruta);

        if ($info === false || ! isset($info[0], $info[1])) {
            throw new RuntimeException('No se pudieron leer las dimensiones de la imagen.');
        }

        $pixeles = (int) $info[0] * (int) $info[1];

        if ($pixeles > self::maximo()) {
            throw new RuntimeException(sprintf(
                'La imagen mide %d x %d px (%.1f megapixeles) y el maximo es %d. Exportala a menor resolucion.',
                $info[0],
                $info[1],
                $pixeles / 1_000_000,
                self::maximo() / 1_000_000,
            ));
        }
    }
}
