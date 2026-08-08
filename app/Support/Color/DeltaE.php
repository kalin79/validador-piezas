<?php

declare(strict_types=1);

namespace App\Support\Color;

/**
 * Diferencia de color CIEDE2000.
 *
 * Se usa CIEDE2000 y no la formula CIE76 (distancia euclidiana en Lab) porque
 * esta ultima sobreestima las diferencias en la zona de los azules y las
 * subestima en los neutros. Para decidir si un color de marca esta "dentro de
 * tolerancia" eso importa: con CIE76 un azul corporativo levemente desviado
 * se marcaria como error y un gris claramente equivocado pasaria.
 *
 * Referencia de la formulacion: Sharma, Wu y Dalal (2005).
 *
 * Escala de interpretacion habitual:
 *   < 1.0  diferencia imperceptible para el ojo humano
 *   1 a 2  perceptible solo comparando lado a lado
 *   2 a 10 perceptible a simple vista
 *   > 10   colores claramente distintos
 */
final class DeltaE
{
    public static function ciede2000(Lab $uno, Lab $otro, float $kL = 1.0, float $kC = 1.0, float $kH = 1.0): float
    {
        $l1 = $uno->l;
        $a1 = $uno->a;
        $b1 = $uno->b;
        $l2 = $otro->l;
        $a2 = $otro->a;
        $b2 = $otro->b;

        // Croma de cada color y su media.
        $c1 = sqrt($a1 ** 2 + $b1 ** 2);
        $c2 = sqrt($a2 ** 2 + $b2 ** 2);
        $cMedia = ($c1 + $c2) / 2.0;

        // Factor G: corrige la falta de uniformidad en la zona de los neutros.
        $g = 0.5 * (1.0 - sqrt(($cMedia ** 7) / (($cMedia ** 7) + (25.0 ** 7))));

        $a1p = (1.0 + $g) * $a1;
        $a2p = (1.0 + $g) * $a2;

        $c1p = sqrt($a1p ** 2 + $b1 ** 2);
        $c2p = sqrt($a2p ** 2 + $b2 ** 2);

        $h1p = self::hueAngle($b1, $a1p);
        $h2p = self::hueAngle($b2, $a2p);

        $deltaLp = $l2 - $l1;
        $deltaCp = $c2p - $c1p;

        // Diferencia de tono, cuidando el salto de 360 grados.
        if ($c1p * $c2p == 0.0) {
            $deltahp = 0.0;
        } elseif (abs($h2p - $h1p) <= 180.0) {
            $deltahp = $h2p - $h1p;
        } elseif ($h2p - $h1p > 180.0) {
            $deltahp = ($h2p - $h1p) - 360.0;
        } else {
            $deltahp = ($h2p - $h1p) + 360.0;
        }

        $deltaHp = 2.0 * sqrt($c1p * $c2p) * sin(deg2rad($deltahp / 2.0));

        $lpMedia = ($l1 + $l2) / 2.0;
        $cpMedia = ($c1p + $c2p) / 2.0;

        // Tono medio, con el mismo cuidado del salto de 360.
        if ($c1p * $c2p == 0.0) {
            $hpMedia = $h1p + $h2p;
        } elseif (abs($h1p - $h2p) <= 180.0) {
            $hpMedia = ($h1p + $h2p) / 2.0;
        } elseif ($h1p + $h2p < 360.0) {
            $hpMedia = ($h1p + $h2p + 360.0) / 2.0;
        } else {
            $hpMedia = ($h1p + $h2p - 360.0) / 2.0;
        }

        $t = 1.0
            - 0.17 * cos(deg2rad($hpMedia - 30.0))
            + 0.24 * cos(deg2rad(2.0 * $hpMedia))
            + 0.32 * cos(deg2rad(3.0 * $hpMedia + 6.0))
            - 0.20 * cos(deg2rad(4.0 * $hpMedia - 63.0));

        $deltaTheta = 30.0 * exp(-((($hpMedia - 275.0) / 25.0) ** 2));

        $rc = 2.0 * sqrt(($cpMedia ** 7) / (($cpMedia ** 7) + (25.0 ** 7)));

        $sl = 1.0 + ((0.015 * (($lpMedia - 50.0) ** 2)) / sqrt(20.0 + (($lpMedia - 50.0) ** 2)));
        $sc = 1.0 + 0.045 * $cpMedia;
        $sh = 1.0 + 0.015 * $cpMedia * $t;

        $rt = -sin(deg2rad(2.0 * $deltaTheta)) * $rc;

        $termL = $deltaLp / ($kL * $sl);
        $termC = $deltaCp / ($kC * $sc);
        $termH = $deltaHp / ($kH * $sh);

        return sqrt(
            $termL ** 2
            + $termC ** 2
            + $termH ** 2
            + $rt * $termC * $termH
        );
    }

    public static function betweenHex(string $unoHex, string $otroHex): float
    {
        return self::ciede2000(
            ColorConverter::hexToLab($unoHex),
            ColorConverter::hexToLab($otroHex),
        );
    }

    /**
     * Angulo de tono en grados, normalizado a [0, 360).
     */
    private static function hueAngle(float $b, float $ap): float
    {
        if ($b == 0.0 && $ap == 0.0) {
            return 0.0;
        }

        $angulo = rad2deg(atan2($b, $ap));

        return $angulo >= 0.0 ? $angulo : $angulo + 360.0;
    }
}
