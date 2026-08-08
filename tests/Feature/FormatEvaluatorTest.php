<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Asset;
use App\Services\Validation\Evaluators\FormatEvaluator;
use Tests\TestCase;

/**
 * Vive en Feature y no en Unit porque necesita la aplicacion arrancada:
 * el evaluador lee los presets desde config('channels').
 */
class FormatEvaluatorTest extends TestCase
{
    private FormatEvaluator $evaluador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluador = new FormatEvaluator();
    }

    private function pieza(?int $w, ?int $h, int $bytes = 500_000): Asset
    {
        $asset = new Asset();
        $asset->width = $w;
        $asset->height = $h;
        $asset->file_size = $bytes;
        $asset->mime_type = 'image/jpeg';

        return $asset;
    }

    /** @return array<int, \App\Services\Validation\FindingDraft> */
    private function evaluar(?int $w, ?int $h, ?string $canal, int $bytes = 500_000): array
    {
        return $this->evaluador->evaluate($this->pieza($w, $h, $bytes), collect(), $canal);
    }

    public function test_no_marca_nada_cuando_la_pieza_cumple_el_preset(): void
    {
        $this->assertSame([], $this->evaluar(1080, 1080, 'instagram_post'));
    }

    public function test_detecta_la_relacion_de_aspecto_equivocada(): void
    {
        $hallazgos = $this->evaluar(1080, 1920, 'instagram_post');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('major', $hallazgos[0]->severity->value);
        $this->assertArrayHasKey('deviation_percent', $hallazgos[0]->evidenceData);
        $this->assertStringContainsString('9:16', $hallazgos[0]->description);
    }

    public function test_tolera_desviaciones_de_aspecto_menores_al_dos_por_ciento(): void
    {
        $this->assertSame([], $this->evaluar(1080, 1090, 'instagram_post'), '0.9% de desvio debe pasar');
        $this->assertNotSame([], $this->evaluar(1080, 1140, 'instagram_post'), '5.3% de desvio no debe pasar');
    }

    public function test_marca_resolucion_insuficiente_como_mayor(): void
    {
        $hallazgos = $this->evaluar(400, 400, 'instagram_post', 100_000);

        $this->assertCount(1, $hallazgos);
        $this->assertSame('major', $hallazgos[0]->severity->value);
        $this->assertStringContainsString('insuficiente', $hallazgos[0]->description);
    }

    public function test_marca_resolucion_excesiva_solo_como_menor(): void
    {
        $hallazgos = $this->evaluar(4000, 4000, 'instagram_post', 2_000_000);

        $this->assertCount(1, $hallazgos);
        $this->assertSame('minor', $hallazgos[0]->severity->value);
    }

    public function test_valida_el_peso_maximo_del_canal(): void
    {
        $excedido = $this->evaluar(1280, 720, 'youtube_thumbnail', 3 * 1024 * 1024);

        $this->assertCount(1, $excedido);
        $this->assertSame(3 * 1024 * 1024, $excedido[0]->evidenceData['file_size']);
        $this->assertSame([], $this->evaluar(1280, 720, 'youtube_thumbnail', 1024 * 1024));
    }

    public function test_no_valida_aspecto_en_canales_de_formato_libre(): void
    {
        $this->assertSame([], $this->evaluar(300, 250, 'display_banner', 100_000));
    }

    public function test_avisa_cuando_el_canal_no_esta_registrado(): void
    {
        $hallazgos = $this->evaluar(1080, 1080, 'canal_inexistente');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('info', $hallazgos[0]->severity->value);
    }

    public function test_no_valida_formato_si_no_se_declaro_canal(): void
    {
        $this->assertSame([], $this->evaluar(1080, 1080, null));
    }

    public function test_reporta_cuando_no_pudo_leer_las_dimensiones(): void
    {
        $hallazgos = $this->evaluar(null, null, 'instagram_post');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('major', $hallazgos[0]->severity->value);
    }
}
