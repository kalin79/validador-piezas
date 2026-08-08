<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Color\DeltaE;
use App\Support\Color\Rgb;
use App\Support\Image\PaletteExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PaletteExtractorTest extends TestCase
{
    private PaletteExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new PaletteExtractor();
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/pe_test_*') ?: [] as $archivo) {
            @unlink($archivo);
        }

        parent::tearDown();
    }

    /**
     * @param  array<int, array{0: string, 1: float}>  $bloques
     */
    private function imagenDeBloques(string $nombre, array $bloques, int $w = 400, int $h = 400): string
    {
        $ruta = sys_get_temp_dir().'/pe_test_'.$nombre;
        $im = imagecreatetruecolor($w, $h);
        $x = 0;

        foreach ($bloques as [$hex, $fraccion]) {
            $rgb = Rgb::fromHex($hex);
            $color = imagecolorallocate($im, $rgb->r, $rgb->g, $rgb->b);
            $ancho = (int) round($w * $fraccion);
            imagefilledrectangle($im, $x, 0, $x + $ancho - 1, $h - 1, $color);
            $x += $ancho;
        }

        imagepng($im, $ruta);
        imagedestroy($im);

        return $ruta;
    }

    public function test_extrae_un_unico_color_de_una_imagen_plana(): void
    {
        $ruta = $this->imagenDeBloques('uno.png', [['#0B3D91', 1.0]]);

        $resultado = $this->extractor->extract($ruta);

        $this->assertCount(1, $resultado);
        $this->assertSame('#0B3D91', $resultado[0]->hex());
        $this->assertSame(100.0, $resultado[0]->percent());
    }

    public function test_respeta_las_proporciones_de_cada_color(): void
    {
        $ruta = $this->imagenDeBloques('tres.png', [
            ['#0B3D91', 0.5],
            ['#FFFFFF', 0.3],
            ['#E30613', 0.2],
        ]);

        $resultado = $this->extractor->extract($ruta);

        $this->assertCount(3, $resultado);
        $this->assertSame('#0B3D91', $resultado[0]->hex(), 'El azul ocupa la mitad, debe encabezar');
        $this->assertEqualsWithDelta(50.0, $resultado[0]->percent(), 2.0);
        $this->assertEqualsWithDelta(30.0, $resultado[1]->percent(), 2.0);
        $this->assertEqualsWithDelta(20.0, $resultado[2]->percent(), 2.0);
    }

    public function test_funde_variaciones_imperceptibles_en_vez_de_reportarlas_por_separado(): void
    {
        $ruta = sys_get_temp_dir().'/pe_test_grad.png';
        $im = imagecreatetruecolor(400, 400);

        for ($x = 0; $x < 400; $x++) {
            $t = $x / 399;
            $color = imagecolorallocate(
                $im,
                (int) (11 + $t * 20),
                (int) (61 + $t * 20),
                (int) (145 + $t * 20)
            );
            imagefilledrectangle($im, $x, 0, $x, 399, $color);
        }

        imagepng($im, $ruta);
        imagedestroy($im);

        $this->assertCount(
            1,
            $this->extractor->extract($ruta),
            'Un degradado sutil debe colapsar en un solo color, no en diez variantes'
        );
    }

    public function test_sobrevive_a_la_compresion_jpeg(): void
    {
        $png = $this->imagenDeBloques('src.png', [
            ['#0B3D91', 0.5],
            ['#FFFFFF', 0.3],
            ['#E30613', 0.2],
        ]);

        $jpg = sys_get_temp_dir().'/pe_test_comp.jpg';
        $im = imagecreatefrompng($png);
        imagejpeg($im, $jpg, 75);
        imagedestroy($im);

        $resultado = $this->extractor->extract($jpg);

        $this->assertLessThan(
            2.0,
            DeltaE::betweenHex($resultado[0]->hex(), '#0B3D91'),
            'El color dominante debe seguir siendo reconocible como el azul de marca'
        );
    }

    public function test_falla_con_mensaje_claro_si_el_archivo_no_es_una_imagen(): void
    {
        $ruta = sys_get_temp_dir().'/pe_test_falso.png';
        file_put_contents($ruta, 'esto no es una imagen');

        $this->expectException(RuntimeException::class);

        $this->extractor->extract($ruta);
    }

    public function test_falla_si_el_archivo_no_existe(): void
    {
        $this->expectException(RuntimeException::class);

        $this->extractor->extract('/tmp/pe_test_inexistente_xyz.png');
    }

    public function test_limita_la_cantidad_de_colores_devueltos(): void
    {
        $bloques = [];
        $paso = 1 / 12;

        for ($i = 0; $i < 12; $i++) {
            $bloques[] = [sprintf('#%02X%02X%02X', $i * 21, 255 - $i * 21, ($i * 60) % 255), $paso];
        }

        $ruta = $this->imagenDeBloques('muchos.png', $bloques, 600, 200);

        $this->assertLessThanOrEqual(4, count($this->extractor->extract($ruta, 4)));
    }
}
