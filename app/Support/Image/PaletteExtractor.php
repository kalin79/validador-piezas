<?php

declare(strict_types=1);

namespace App\Support\Image;

use App\Support\Color\ColorConverter;
use App\Support\Color\DeltaE;
use App\Support\Color\Rgb;
use GdImage;
use RuntimeException;

/**
 * Extrae los colores dominantes de una imagen.
 *
 * Estrategia en tres pasos:
 *
 * 1. Reduccion. La imagen se escala a un lado maximo pequeno. Analizar una
 *    pieza de 4000px no da un resultado mas fiel que analizar una de 160px:
 *    solo multiplica el trabajo. El escalado promedia vecinos, que ademas
 *    atenua el ruido de compresion JPEG.
 *
 * 2. Cuantizacion en cubos. Cada canal se divide en 16 niveles (4096 cubos).
 *    Se acumulan la suma de canales y el conteo por cubo, y al final se
 *    devuelve el promedio real de cada cubo, no el centro del cubo. Asi el
 *    color reportado existe de verdad en la imagen.
 *
 * 3. Fusion perceptual. Cubos vecinos que estan a menos de cierto Delta E se
 *    funden. Sin este paso una pieza con un degradado azul devolveria diez
 *    variantes del mismo azul y ningun otro color.
 */
final class PaletteExtractor
{
    public function __construct(
        private int $sampleSize = 160,
        private int $levels = 16,
        private float $mergeThreshold = 6.0,
    ) {}

    /**
     * @return array<int, ExtractedColor> ordenados de mayor a menor presencia
     */
    public function extract(string $path, int $max = 8): array
    {
        $image = $this->open($path);

        try {
            $small = $this->downscale($image);

            try {
                $buckets = $this->accumulate($small);
            } finally {
                if ($small !== $image) {
                    imagedestroy($small);
                }
            }
        } finally {
            imagedestroy($image);
        }

        if ($buckets === []) {
            return [];
        }

        $colors = $this->average($buckets);
        $colors = $this->mergeSimilar($colors);

        usort($colors, static fn (array $a, array $b): int => $b['pixels'] <=> $a['pixels']);

        $total = array_sum(array_column($colors, 'pixels'));

        return array_map(
            function (array $c) use ($total): ExtractedColor {
                $rgb = new Rgb($c['r'], $c['g'], $c['b']);

                return new ExtractedColor(
                    rgb: $rgb,
                    lab: ColorConverter::rgbToLab($rgb),
                    share: $total > 0 ? $c['pixels'] / $total : 0.0,
                    pixels: $c['pixels'],
                );
            },
            array_slice($colors, 0, $max)
        );
    }

    private function open(string $path): GdImage
    {
        if (! is_readable($path)) {
            throw new RuntimeException("No se puede leer la imagen: {$path}");
        }

        LimiteDePixeles::verificar($path);

        $info = @getimagesize($path);

        if ($info === false) {
            throw new RuntimeException("El archivo no es una imagen valida: {$path}");
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException("Formato no soportado para analisis de color: {$path}");
        }

        return $image;
    }

    private function downscale(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $lado = max($w, $h);

        if ($lado <= $this->sampleSize) {
            return $image;
        }

        $factor = $this->sampleSize / $lado;
        $scaled = imagescale($image, max(1, (int) round($w * $factor)), max(1, (int) round($h * $factor)));

        return $scaled === false ? $image : $scaled;
    }

    /**
     * @return array<int, array{r: int, g: int, b: int, pixels: int}>
     */
    private function accumulate(GdImage $image): array
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $paso = 256 / $this->levels;
        $buckets = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $color = imagecolorat($image, $x, $y);

                // Descarta pixeles mayormente transparentes: no son color de la pieza.
                $alpha = ($color >> 24) & 0x7F;
                if ($alpha > 96) {
                    continue;
                }

                $r = ($color >> 16) & 0xFF;
                $g = ($color >> 8) & 0xFF;
                $b = $color & 0xFF;

                $key = (int) ($r / $paso) * $this->levels * $this->levels
                    + (int) ($g / $paso) * $this->levels
                    + (int) ($b / $paso);

                if (! isset($buckets[$key])) {
                    $buckets[$key] = ['r' => 0, 'g' => 0, 'b' => 0, 'pixels' => 0];
                }

                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
                $buckets[$key]['pixels']++;
            }
        }

        return $buckets;
    }

    /**
     * @param  array<int, array{r: int, g: int, b: int, pixels: int}>  $buckets
     * @return array<int, array{r: int, g: int, b: int, pixels: int}>
     */
    private function average(array $buckets): array
    {
        return array_values(array_map(
            static fn (array $b): array => [
                'r' => (int) round($b['r'] / $b['pixels']),
                'g' => (int) round($b['g'] / $b['pixels']),
                'b' => (int) round($b['b'] / $b['pixels']),
                'pixels' => $b['pixels'],
            ],
            $buckets
        ));
    }

    /**
     * @param  array<int, array{r: int, g: int, b: int, pixels: int}>  $colors
     * @return array<int, array{r: int, g: int, b: int, pixels: int}>
     */
    private function mergeSimilar(array $colors): array
    {
        usort($colors, static fn (array $a, array $b): int => $b['pixels'] <=> $a['pixels']);

        $result = [];

        foreach ($colors as $color) {
            $lab = ColorConverter::rgbToLab(new Rgb($color['r'], $color['g'], $color['b']));
            $fusionado = false;

            foreach ($result as $i => $existente) {
                $labExistente = ColorConverter::rgbToLab(
                    new Rgb($existente['r'], $existente['g'], $existente['b'])
                );

                if (DeltaE::ciede2000($lab, $labExistente) >= $this->mergeThreshold) {
                    continue;
                }

                // Media ponderada por cantidad de pixeles: el color dominante manda.
                $total = $existente['pixels'] + $color['pixels'];

                $result[$i] = [
                    'r' => (int) round(($existente['r'] * $existente['pixels'] + $color['r'] * $color['pixels']) / $total),
                    'g' => (int) round(($existente['g'] * $existente['pixels'] + $color['g'] * $color['pixels']) / $total),
                    'b' => (int) round(($existente['b'] * $existente['pixels'] + $color['b'] * $color['pixels']) / $total),
                    'pixels' => $total,
                ];

                $fusionado = true;
                break;
            }

            if (! $fusionado) {
                $result[] = $color;
            }
        }

        return $result;
    }
}
