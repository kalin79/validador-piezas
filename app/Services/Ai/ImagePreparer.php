<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Support\Color\Rgb;
use GdImage;
use RuntimeException;

/**
 * Prepara la pieza para enviarla al modelo.
 *
 * Redimensionar no es una optimizacion opcional: la API rechaza imagenes de
 * mas de 10 MB codificadas en base64, y por encima del lado maximo el modelo
 * las reduce de todos modos antes de analizarlas. Hacerlo de este lado ahorra
 * ancho de banda y deja el tamano bajo control.
 *
 * Se conserva la relacion de aspecto: las coordenadas del logo que devuelve el
 * modelo vienen normalizadas de 0 a 1, y solo son trasladables a la pieza
 * original si no hubo deformacion.
 */
final class ImagePreparer
{
    /**
     * @return array{data: string, media_type: string, width: int, height: int, bytes: int}
     */
    public function prepare(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("No se puede leer la pieza: {$path}");
        }

        \App\Support\Image\LimiteDePixeles::verificar($path);

        $info = @getimagesize($path);

        if ($info === false) {
            throw new RuntimeException("El archivo no es una imagen valida: {$path}");
        }

        [$anchoOriginal, $altoOriginal] = $info;
        $maxSide = (int) config('ai.image.max_side', 1568);

        [$ancho, $alto] = TokenEstimator::fitWithin($anchoOriginal, $altoOriginal, $maxSide);

        // Si ya cabe y es JPEG o PNG, se envia tal cual: reencodear degrada.
        if ($ancho === $anchoOriginal
            && $alto === $altoOriginal
            && in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
            $bytes = (int) filesize($path);
            $this->assertSize($bytes);

            return [
                'data' => base64_encode((string) file_get_contents($path)),
                'media_type' => $info['mime'],
                'width' => $ancho,
                'height' => $alto,
                'bytes' => $bytes,
            ];
        }

        $original = $this->open($path, $info[2]);

        try {
            $destino = imagescale($original, $ancho, $alto);

            if ($destino === false) {
                throw new RuntimeException('No se pudo redimensionar la pieza.');
            }

            try {
                // Fondo blanco: JPEG no admite transparencia y el negro por
                // defecto de GD alteraria los colores que se van a evaluar.
                $plano = imagecreatetruecolor($ancho, $alto);
                $blanco = imagecolorallocate($plano, 255, 255, 255);
                imagefilledrectangle($plano, 0, 0, $ancho, $alto, $blanco);
                imagecopy($plano, $destino, 0, 0, 0, 0, $ancho, $alto);

                ob_start();
                imagejpeg($plano, null, (int) config('ai.image.jpeg_quality', 85));
                $binario = (string) ob_get_clean();

                imagedestroy($plano);
            } finally {
                imagedestroy($destino);
            }
        } finally {
            imagedestroy($original);
        }

        $codificado = base64_encode($binario);
        $this->assertSize(strlen($codificado));

        return [
            'data' => $codificado,
            'media_type' => 'image/jpeg',
            'width' => $ancho,
            'height' => $alto,
            'bytes' => strlen($binario),
        ];
    }

    private function assertSize(int $bytes): void
    {
        $max = (int) config('ai.image.max_base64_bytes', 10 * 1024 * 1024);

        if ($bytes > $max) {
            throw AiException::imageTooLarge($bytes, $max);
        }
    }

    private function open(string $path, int $type): GdImage
    {
        $imagen = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            default => false,
        };

        if ($imagen === false) {
            throw new RuntimeException("Formato no soportado para envio al modelo: {$path}");
        }

        return $imagen;
    }
}
