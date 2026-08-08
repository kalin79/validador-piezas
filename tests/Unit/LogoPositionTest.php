<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LogoPosition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LogoPositionTest extends TestCase
{
    /** @return array<string, array{0: float, 1: float, 2: string}> */
    public static function puntos(): array
    {
        return [
            'superior izquierda' => [0.10, 0.10, 'top_left'],
            'superior centro' => [0.50, 0.10, 'top_center'],
            'superior derecha' => [0.90, 0.10, 'top_right'],
            'medio izquierda' => [0.10, 0.50, 'middle_left'],
            'centro' => [0.50, 0.50, 'center'],
            'medio derecha' => [0.90, 0.50, 'middle_right'],
            'inferior izquierda' => [0.10, 0.90, 'bottom_left'],
            'inferior centro' => [0.50, 0.90, 'bottom_center'],
            'inferior derecha' => [0.90, 0.90, 'bottom_right'],
            'esquina exacta 0,0' => [0.0, 0.0, 'top_left'],
            'esquina exacta 1,1' => [1.0, 1.0, 'bottom_right'],
        ];
    }

    #[DataProvider('puntos')]
    public function test_ubica_el_punto_en_la_celda_correcta(float $x, float $y, string $esperado): void
    {
        $this->assertSame($esperado, LogoPosition::fromPoint($x, $y)->value);
    }

    public function test_el_centro_no_se_llama_middle_center(): void
    {
        $this->assertSame(LogoPosition::Center, LogoPosition::fromPoint(0.5, 0.5));
    }

    public function test_ubica_un_logo_por_el_centro_de_su_recuadro(): void
    {
        // Recuadro x=0.72 y=0.82 ancho=0.20 alto=0.10 -> centro (0.82, 0.87)
        $posicion = LogoPosition::fromPoint(0.72 + 0.20 / 2, 0.82 + 0.10 / 2);

        $this->assertSame(LogoPosition::BottomRight, $posicion);
    }

    public function test_todas_las_celdas_tienen_etiqueta(): void
    {
        foreach (LogoPosition::cases() as $caso) {
            $this->assertNotEmpty($caso->label());
        }

        $this->assertCount(9, LogoPosition::cases());
    }
}
