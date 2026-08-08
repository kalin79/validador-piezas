<?php

declare(strict_types=1);

namespace App\Support\Color;

/**
 * Conversion sRGB a CIELAB usando iluminante D65 y observador estandar de 2 grados.
 *
 * Se convierte a Lab en vez de comparar hexadecimales porque la distancia
 * euclidiana en RGB no corresponde a la diferencia que percibe el ojo: dos
 * azules separados por 20 unidades de RGB pueden verse identicos, mientras que
 * dos verdes con la misma separacion se ven claramente distintos.
 */
final class ColorConverter
{
    /** Punto blanco D65, observador 2 grados. */
    private const REF_X = 95.047;

    private const REF_Y = 100.000;

    private const REF_Z = 108.883;

    public static function rgbToLab(Rgb $rgb): Lab
    {
        [$x, $y, $z] = self::rgbToXyz($rgb);

        $fx = self::pivotXyz($x / self::REF_X);
        $fy = self::pivotXyz($y / self::REF_Y);
        $fz = self::pivotXyz($z / self::REF_Z);

        return new Lab(
            l: 116.0 * $fy - 16.0,
            a: 500.0 * ($fx - $fy),
            b: 200.0 * ($fy - $fz),
        );
    }

    public static function hexToLab(string $hex): Lab
    {
        return self::rgbToLab(Rgb::fromHex($hex));
    }

    /**
     * Luminancia relativa segun WCAG 2.x.
     *
     * @return float entre 0.0 (negro) y 1.0 (blanco)
     */
    public static function relativeLuminance(Rgb $rgb): float
    {
        $canales = [];

        foreach ([$rgb->r, $rgb->g, $rgb->b] as $valor) {
            $c = $valor / 255.0;
            $canales[] = $c <= 0.04045
                ? $c / 12.92
                : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
    }

    /**
     * Ratio de contraste WCAG entre dos colores. Va de 1.0 a 21.0.
     */
    public static function contrastRatio(Rgb $uno, Rgb $otro): float
    {
        $l1 = self::relativeLuminance($uno);
        $l2 = self::relativeLuminance($otro);

        $claro = max($l1, $l2);
        $oscuro = min($l1, $l2);

        return ($claro + 0.05) / ($oscuro + 0.05);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgbToXyz(Rgb $rgb): array
    {
        $canales = [];

        foreach ([$rgb->r, $rgb->g, $rgb->b] as $valor) {
            $c = $valor / 255.0;
            $canales[] = ($c > 0.04045 ? (($c + 0.055) / 1.055) ** 2.4 : $c / 12.92) * 100.0;
        }

        [$r, $g, $b] = $canales;

        return [
            $r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375,
            $r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750,
            $r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041,
        ];
    }

    private static function pivotXyz(float $t): float
    {
        return $t > 0.008856
            ? $t ** (1 / 3)
            : (7.787 * $t) + (16.0 / 116.0);
    }
}
