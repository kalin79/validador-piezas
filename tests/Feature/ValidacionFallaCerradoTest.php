<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Enums\VerdictStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Client;
use App\Models\RuleSet;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationRun;
use App\Services\Ai\AiException;
use App\Services\Ai\FakeProvider;
use App\Services\Ai\VisionProvider;
use App\Services\Ai\VisionProviderFactory;
use App\Services\Ai\VisionRequest;
use App\Services\Ai\VisionResponse;
use App\Services\Validation\AiEvaluator;
use App\Services\Validation\RuleStatusReport;
use App\Services\ValidationRunner;
use Database\Seeders\PromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El requisito central del sistema: nunca afirmar que una pieza cumple sin
 * evidencia. Cada test es una forma distinta en que antes salia "Aprobado 100"
 * sin que nadie hubiera verificado nada.
 */
class ValidacionFallaCerradoTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private RuleSet $conjunto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(PromptTemplateSeeder::class);

        $client = Client::create(['name' => 'Cliente', 'slug' => 'cliente']);
        $this->brand = Brand::create(['client_id' => $client->id, 'name' => 'Marca', 'slug' => 'marca']);

        $this->conjunto = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand,
            'owner_id' => $this->brand->id,
            'client_id' => $client->id,
            'version' => 1,
            'name' => 'Marca v1',
            'status' => RuleSetStatus::Published,
        ]);
    }

    private function regla(string $code, string $type, string $category, array $extra = []): void
    {
        $this->conjunto->rules()->create(array_merge([
            'code' => $code,
            'category' => $category,
            'type' => $type,
            'severity' => 'major',
            'title' => "Regla {$code}",
            'statement' => 'Enunciado de prueba.',
            'is_active' => true,
        ], $extra));
    }

    private function pieza(?string $canal = 'instagram_post'): Asset
    {
        $img = imagecreatetruecolor(1080, 1080);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 60, 200));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        Storage::disk('local')->put('piezas/p.png', $png);

        $user = User::create(['name' => 'Ana', 'email' => 'ana@test.local', 'password' => 'x', 'is_active' => true]);

        $submission = Submission::create([
            'brand_id' => $this->brand->id,
            'user_id' => $user->id,
            'channel' => $canal,
        ]);

        return Asset::create([
            'submission_id' => $submission->id,
            'brand_id' => $this->brand->id,
            'original_filename' => 'p.png',
            'storage_disk' => 'local',
            'storage_path' => 'piezas/p.png',
            'file_hash' => hash('sha256', $png),
            'mime_type' => 'image/png',
            'file_size' => strlen($png),
            'width' => 1080,
            'height' => 1080,
            'extracted_palette' => [],
        ]);
    }

    /** @param  array<string, mixed>|\Throwable  $salida */
    private function runner(array|\Throwable $salida, ?string $stopReason = 'tool_use'): ValidationRunner
    {
        $proveedor = new class($salida, $stopReason) implements VisionProvider
        {
            public function __construct(private array|\Throwable $salida, private ?string $stop) {}

            public function name(): string
            {
                return 'stub';
            }

            public function analyze(VisionRequest $request): VisionResponse
            {
                if ($this->salida instanceof \Throwable) {
                    throw $this->salida;
                }

                return new VisionResponse(
                    data: $this->salida,
                    raw: ['stub' => true],
                    model: 'stub-model',
                    inputTokens: 1,
                    outputTokens: 1,
                    costUsd: 0.0,
                    stopReason: $this->stop,
                );
            }
        };

        return new ValidationRunner(ai: new AiEvaluator(provider: $proveedor));
    }

    private function estado(ValidationRun $run): VerdictStatus
    {
        return $run->verdict->status;
    }

    public function test_si_la_ia_falla_la_pieza_no_se_aprueba(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');

        $run = $this->runner(new AiException('timeout'))->run($this->pieza());

        $this->assertSame(VerdictStatus::NotEvaluated, $this->estado($run));
        $this->assertSame('error', $run->deterministic_results['coverage']['COMP-001']['outcome']);
    }

    public function test_si_la_ia_falla_y_lo_determinista_si_se_midio_requiere_revision(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');
        $this->regla('FMT-501', 'deterministic', 'composition');

        $run = $this->runner(new AiException('429'))->run($this->pieza());

        $this->assertSame(VerdictStatus::RequiresReview, $this->estado($run));
        $this->assertFalse($run->verdict->status->habilitaEnvio());
    }

    public function test_lista_de_hallazgos_vacia_sin_pronunciamiento_no_es_cumplimiento(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');

        $run = $this->runner(['findings' => []])->run($this->pieza());

        $this->assertNotSame(VerdictStatus::Approved, $this->estado($run));
        $this->assertSame('not_determinable', $run->deterministic_results['coverage']['COMP-001']['outcome']);
    }

    public function test_con_pronunciamiento_explicito_y_evidencia_si_se_aprueba(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');
        $this->regla('FMT-501', 'deterministic', 'composition');

        $run = $this->runner([
            'findings' => [],
            'rule_assessments' => [
                ['rule_code' => 'COMP-001', 'status' => 'cumple', 'evidence' => 'Incluye la leyenda legal completa.', 'confidence' => 0.9],
            ],
        ])->run($this->pieza());

        $this->assertSame(VerdictStatus::Approved, $this->estado($run));

        $reporte = RuleStatusReport::for($run);
        $this->assertSame(['COMP-001', 'FMT-501'], collect(RuleStatusReport::codes($reporte, 'cumple'))->sort()->values()->all());
    }

    public function test_no_determinable_declarado_por_el_modelo_manda_a_revision(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');
        $this->regla('FMT-501', 'deterministic', 'composition');

        $run = $this->runner([
            'findings' => [],
            'rule_assessments' => [
                ['rule_code' => 'COMP-001', 'status' => 'no_determinable', 'evidence' => 'La leyenda es ilegible.', 'confidence' => 0.8],
            ],
        ])->run($this->pieza());

        $this->assertSame(VerdictStatus::RequiresReview, $this->estado($run));
    }

    public function test_respuesta_truncada_no_se_usa(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');

        // El proveedor real lanza esta excepcion al ver stop_reason=max_tokens.
        $run = $this->runner(AiException::truncated('max_tokens'))->run($this->pieza());

        $this->assertSame(VerdictStatus::NotEvaluated, $this->estado($run));
        $this->assertStringContainsString('max_tokens', (string) $run->error_message);
    }

    public function test_regla_determinista_sin_canal_no_se_reporta_como_cumplida(): void
    {
        $this->regla('FMT-501', 'deterministic', 'composition');

        $run = $this->runner(['findings' => []])->run($this->pieza(canal: null));

        $this->assertSame(VerdictStatus::NotEvaluated, $this->estado($run));
        $this->assertSame([], RuleStatusReport::codes(RuleStatusReport::for($run), 'cumple'));
    }

    public function test_regla_determinista_sin_evaluador_no_se_reporta_como_cumplida(): void
    {
        $this->regla('COPY-501', 'deterministic', 'copy');

        $run = $this->runner(['findings' => []])->run($this->pieza());

        $this->assertSame(VerdictStatus::NotEvaluated, $this->estado($run));
        $this->assertSame('not_evaluated', $run->deterministic_results['coverage']['COPY-501']['outcome']);
    }

    public function test_paleta_sin_extraer_no_se_reporta_como_cumplida(): void
    {
        $this->regla('PAL-501', 'deterministic', 'palette');
        $this->regla('FMT-501', 'deterministic', 'composition');

        $run = $this->runner(['findings' => []])->run($this->pieza());

        $this->assertSame(VerdictStatus::RequiresReview, $this->estado($run));
        $this->assertSame('not_determinable', $run->deterministic_results['coverage']['PAL-501']['outcome']);
    }

    public function test_el_driver_simulado_no_produce_veredicto_ni_evidencia(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');

        $runner = new ValidationRunner(ai: new AiEvaluator(provider: new FakeProvider()));
        $pieza = $this->pieza();
        $run = $runner->run($pieza);

        $this->assertSame(VerdictStatus::NotEvaluated, $this->estado($run));
        $this->assertCount(0, $run->findings);
        $this->assertNull($pieza->fresh()->extracted_text);
        $this->assertEquals(0, (float) $run->cost_usd);
    }

    public function test_el_driver_simulado_esta_prohibido_en_produccion(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(AiException::class);

        VisionProviderFactory::make('fake');
    }

    public function test_un_bloqueante_real_rechaza_aunque_haya_reglas_pendientes(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance', ['severity' => 'blocking']);
        $this->regla('COMP-002', 'judgment', 'compliance');

        $run = $this->runner([
            'findings' => [[
                'rule_code' => 'COMP-001', 'category' => 'compliance', 'severity' => 'blocking',
                'description' => 'Promete rentabilidad garantizada.', 'evidence' => 'rentabilidad garantizada', 'confidence' => 0.95,
            ]],
        ])->run($this->pieza());

        $this->assertSame(VerdictStatus::Rejected, $this->estado($run));
    }

    public function test_las_vistas_muestran_las_reglas_no_verificadas(): void
    {
        $this->regla('COMP-001', 'judgment', 'compliance');
        $this->regla('FMT-501', 'deterministic', 'composition');
        $this->regla('PAL-501', 'deterministic', 'palette');

        $run = $this->runner([
            'findings' => [],
            'rule_assessments' => [['rule_code' => 'COMP-001', 'status' => 'no_determinable', 'evidence' => 'ilegible', 'confidence' => 0.7]],
        ])->run($this->pieza());

        $run->load(['verdict', 'findings', 'asset']);
        $html = view('filament.modals.hallazgos', ['run' => $run, 'asset' => $run->asset, 'url' => $run->asset->url()])->render();
        $this->assertStringContainsString('no se pudieron verificar', $html);

        $html2 = view('filament.modals.historial', ['asset' => $run->asset])->render();
        $this->assertNotEmpty($html2);
    }
}
