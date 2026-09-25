<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Severity;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Services\Validation\Evaluators\LogoEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El logo lo detecta el modelo y lo mide el codigo. Si lo que devuelve el
 * modelo no alcanza para medir, no se inventa la medicion.
 */
class LogoEvaluatorCoberturaTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['name' => 'C', 'slug' => 'c']);
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'B', 'slug' => 'b']);

        BrandAsset::withoutEvents(fn () => BrandAsset::create([
            'brand_id' => $brand->id,
            'name' => 'Logo principal',
            'type' => 'logo_primary',
            'storage_path' => 'marca/logo.png',
            'file_hash' => str_repeat('a', 64),
            'mime_type' => 'image/png',
            'file_size' => 10,
            'is_required' => true,
            'is_active' => true,
            'min_width_percent' => 10,
        ]));

        $this->asset = new Asset(['brand_id' => $brand->id]);
    }

    public function test_ausencia_con_confianza_baja_no_bloquea(): void
    {
        $r = (new LogoEvaluator())->evaluateWithCoverage($this->asset, ['detected' => false, 'confidence' => 0.3], null);

        $this->assertSame([], $r['findings']);
        $this->assertNotNull($r['undetermined']);
    }

    public function test_ausencia_con_confianza_alta_si_bloquea(): void
    {
        $r = (new LogoEvaluator())->evaluateWithCoverage($this->asset, ['detected' => false, 'confidence' => 0.9], null);

        $this->assertSame(Severity::Blocking, $r['findings'][0]->severity);
    }

    public function test_coordenadas_incompletas_no_producen_mediciones_inventadas(): void
    {
        $r = (new LogoEvaluator())->evaluateWithCoverage($this->asset, [
            'detected' => true, 'confidence' => 0.9, 'bounding_box' => ['x' => 0.1, 'y' => 0.1],
        ], null);

        $this->assertSame([], $r['findings']);
        $this->assertNotNull($r['undetermined']);
    }

    public function test_coordenadas_en_pixeles_se_rechazan(): void
    {
        $r = (new LogoEvaluator())->evaluateWithCoverage($this->asset, [
            'detected' => true, 'confidence' => 0.9,
            'bounding_box' => ['x' => 820, 'y' => 900, 'width' => 120, 'height' => 60],
        ], null);

        $this->assertSame([], $r['findings']);
        $this->assertNotNull($r['undetermined']);
    }

    public function test_con_caja_valida_mide_normalmente(): void
    {
        $r = (new LogoEvaluator())->evaluateWithCoverage($this->asset, [
            'detected' => true, 'confidence' => 0.9,
            'bounding_box' => ['x' => 0.8, 'y' => 0.85, 'width' => 0.05, 'height' => 0.05],
        ], null);

        $this->assertNull($r['undetermined']);
        $this->assertSame(Severity::Major, $r['findings'][0]->severity); // 5% < 10% minimo
    }
}
