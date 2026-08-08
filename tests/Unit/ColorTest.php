<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Color\ColorConverter;
use App\Support\Color\DeltaE;
use App\Support\Color\Lab;
use App\Support\Color\Rgb;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColorTest extends TestCase
{
    /**
     * Pares de referencia de Sharma, Wu y Dalal (2005), publicados justamente
     * para que las implementaciones de CIEDE2000 puedan comprobarse. Si alguno
     * falla, el error esta en la formula, no en el test.
     *
     * @return array<string, array{0: array<int, float>, 1: array<int, float>, 2: float}>
     */
    public static function paresDeSharma(): array
    {
        return [
            'azul 1'      => [[50.0000, 2.6772, -79.7751], [50.0000, 0.0000, -82.7485], 2.0425],
            'azul 2'      => [[50.0000, 3.1571, -77.2803], [50.0000, 0.0000, -82.7485], 2.8615],
            'azul 3'      => [[50.0000, 2.8361, -74.0200], [50.0000, 0.0000, -82.7485], 3.4412],
            'azul 4'      => [[50.0000, -1.3802, -84.2814], [50.0000, 0.0000, -82.7485], 1.0000],
            'neutro'      => [[50.0000, 0.0000, 0.0000], [50.0000, -1.0000, 2.0000], 2.3669],
            'croma cero'  => [[50.0000, 2.4900, -0.0010], [50.0000, -2.4900, 0.0009], 7.1792],
            'salto tono'  => [[50.0000, -0.0010, 2.4900], [50.0000, 0.0009, -2.4900], 4.8045],
            'lejano 1'    => [[50.0000, 2.5000, 0.0000], [73.0000, 25.0000, -18.0000], 27.1492],
            'lejano 2'    => [[50.0000, 2.5000, 0.0000], [56.0000, -27.0000, -3.0000], 31.9030],
            'verde'       => [[60.2574, -34.0099, 36.2677], [60.4626, -34.1751, 39.4387], 1.2644],
            'violeta'     => [[22.7233, 20.0904, -46.6940], [23.0331, 14.9730, -42.5619], 2.0373],
            'casi blanco' => [[90.8027, -2.0831, 1.4410], [91.1528, -1.6435, 0.0447], 1.4441],
            'casi negro'  => [[2.0776, 0.0795, -1.1350], [0.9033, -0.0636, -0.5514], 0.9082],
        ];
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    #[DataProvider('paresDeSharma')]
    public function test_ciede2000_coincide_con_el_dataset_de_referencia(array $a, array $b, float $esperado): void
    {
        $obtenido = DeltaE::ciede2000(new Lab(...$a), new Lab(...$b));

        $this->assertEqualsWithDelta(
            $esperado,
            $obtenido,
            0.0001,
            sprintf('Esperado %.4f, obtenido %.4f', $esperado, $obtenido)
        );
    }

    public function test_la_distancia_es_simetrica(): void
    {
        $a = new Lab(50, 2.5, 0);
        $b = new Lab(61, -5, 29);

        $this->assertSame(
            DeltaE::ciede2000($a, $b),
            DeltaE::ciede2000($b, $a),
            'dE(A,B) debe ser identico a dE(B,A)'
        );
    }

    public function test_el_mismo_color_da_distancia_cero(): void
    {
        $this->assertSame(0.0, DeltaE::betweenHex('#0B3D91', '#0B3D91'));
    }

    public function test_distingue_desviacion_imperceptible_de_una_real(): void
    {
        $this->assertLessThan(
            1.0,
            DeltaE::betweenHex('#0B3D91', '#0C3E93'),
            'Una variacion de un digito hexadecimal debe ser imperceptible'
        );

        $this->assertGreaterThan(
            2.5,
            DeltaE::betweenHex('#0B3D91', '#1E5AB8'),
            'Un azul visiblemente distinto debe superar la tolerancia tipica'
        );
    }

    public function test_contraste_wcag_con_valores_canonicos(): void
    {
        $blanco = Rgb::fromHex('#FFFFFF');
        $negro = Rgb::fromHex('#000000');

        $this->assertEqualsWithDelta(21.0, ColorConverter::contrastRatio($blanco, $negro), 0.0001);
        $this->assertEqualsWithDelta(1.0, ColorConverter::contrastRatio($blanco, $blanco), 0.0001);
        $this->assertEqualsWithDelta(1.0, ColorConverter::relativeLuminance($blanco), 0.000001);
        $this->assertEqualsWithDelta(0.0, ColorConverter::relativeLuminance($negro), 0.000001);
    }

    public function test_reconoce_el_gris_limite_de_accesibilidad_aa(): void
    {
        $ratio = ColorConverter::contrastRatio(Rgb::fromHex('#767676'), Rgb::fromHex('#FFFFFF'));

        $this->assertGreaterThanOrEqual(4.5, $ratio);
        $this->assertLessThan(4.6, $ratio);
    }

    public function test_rechaza_hexadecimales_invalidos(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Rgb::fromHex('#GGGGGG');
    }

    public function test_acepta_hexadecimales_de_tres_digitos(): void
    {
        $this->assertSame('#FFFFFF', Rgb::fromHex('#FFF')->toHex());
        $this->assertSame('#0033AA', Rgb::fromHex('03A')->toHex());
    }

    public function test_convierte_a_lab_los_extremos_conocidos(): void
    {
        $blanco = ColorConverter::hexToLab('#FFFFFF');
        $negro = ColorConverter::hexToLab('#000000');

        $this->assertEqualsWithDelta(100.0, $blanco->l, 0.01, 'El blanco debe tener L cercano a 100');
        $this->assertEqualsWithDelta(0.0, $negro->l, 0.01, 'El negro debe tener L igual a 0');
    }
}
