<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\TokenEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vive en Unit pero extiende Tests\TestCase porque costUsd lee config('ai').
 */
class TokenEstimatorTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int, 2: int}> */
    public static function imagenes(): array
    {
        return [
            'miniatura 200x200' => [200, 200, 64],
            'cuadrada 1000x1000' => [1000, 1000, 1296],
            'un solo parche' => [28, 28, 1],
            'un pixel' => [1, 1, 1],
            'full HD acotada' => [1920, 1080, 1568],
            '4K acotada' => [3840, 2160, 1568],
        ];
    }

    #[DataProvider('imagenes')]
    public function test_calcula_los_tokens_visuales(int $w, int $h, int $esperado): void
    {
        $this->assertSame($esperado, TokenEstimator::imageTokens($w, $h));
    }

    public function test_el_redimensionado_conserva_la_relacion_de_aspecto(): void
    {
        [$w, $h] = TokenEstimator::fitWithin(1920, 1080, 1568);

        $this->assertLessThanOrEqual(1568, max($w, $h));
        $this->assertEqualsWithDelta(1920 / 1080, $w / $h, 0.02);
    }

    public function test_no_agranda_una_imagen_pequena(): void
    {
        $this->assertSame([800, 600], TokenEstimator::fitWithin(800, 600, 1568));
    }

    public function test_calcula_el_costo_segun_la_tarifa_del_modelo(): void
    {
        $this->assertEqualsWithDelta(3.0, TokenEstimator::costUsd('claude-sonnet-5', 1_000_000, 0), 0.0001);
        $this->assertEqualsWithDelta(15.0, TokenEstimator::costUsd('claude-sonnet-5', 0, 1_000_000), 0.0001);
    }

    public function test_un_modelo_desconocido_no_rompe_el_calculo(): void
    {
        $this->assertSame(0.0, TokenEstimator::costUsd('modelo-inexistente', 1000, 1000));
    }

    public function test_estima_el_costo_de_una_validacion_tipica(): void
    {
        $estimacion = TokenEstimator::estimate(
            model: 'claude-sonnet-5',
            imageWidth: 1080,
            imageHeight: 1080,
            systemPrompt: str_repeat('a', 2800),
            userPrompt: str_repeat('b', 5250),
        );

        $this->assertSame(1521, $estimacion['image_tokens']);
        $this->assertGreaterThan(0, $estimacion['cost_usd']);
        $this->assertLessThan(0.10, $estimacion['cost_usd'], 'Una pieza no deberia costar mas de 10 centavos');
    }
}
