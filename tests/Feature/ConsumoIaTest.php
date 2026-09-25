<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ConsumoIa;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Submission;
use App\Models\Team;
use App\Models\User;
use App\Models\ValidationRun;
use App\Services\Consumo\ReporteDeConsumo;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reporte de consumo de IA por cliente: que cuenta, que excluye, como
 * calcula el costo y quien puede verlo.
 */
class ConsumoIaTest extends TestCase
{
    use RefreshDatabase;

    private Client $alfa;

    private Client $beta;

    private Brand $marcaAlfa;

    private Brand $marcaBeta;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Tarifas fijas para que el test no dependa de futuros cambios de precio.
        config(['ai.pricing' => [
            'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        ]]);

        $this->alfa = Client::create(['name' => 'Alfa', 'slug' => 'alfa', 'is_active' => true]);
        $this->beta = Client::create(['name' => 'Beta', 'slug' => 'beta', 'is_active' => true]);
        $this->marcaAlfa = Brand::create(['client_id' => $this->alfa->id, 'name' => 'Marca Alfa', 'slug' => 'ma', 'is_active' => true]);
        $this->marcaBeta = Brand::create(['client_id' => $this->beta->id, 'name' => 'Marca Beta', 'slug' => 'mb', 'is_active' => true]);

        $hoy = CarbonImmutable::parse('2026-09-15 12:00', 'America/Lima');
        CarbonImmutable::setTestNow($hoy);

        // Alfa: Sonnet 5 guardado con la tarifa vieja (3/15 => 4.50), vale 3.00 hoy.
        $this->ejecucion($this->marcaAlfa, 'claude-sonnet-5', 1_000_000, 100_000, 4.5, $hoy);
        // Alfa: modelo sin tarifa: tokens si, costo no.
        $this->ejecucion($this->marcaAlfa, 'modelo-raro', 1_000, 1_000, 0, $hoy);
        // Alfa: simulado, sin tokens, fuera de rango: no cuentan.
        $this->ejecucion($this->marcaAlfa, 'claude-sonnet-5', 500, 500, 0, $hoy, simulado: true);
        $this->ejecucion($this->marcaAlfa, 'claude-sonnet-5', 0, 0, 0, $hoy);
        $this->ejecucion($this->marcaAlfa, 'claude-sonnet-5', 9_000_000, 9_000_000, 99, $hoy->subYear());
        // Beta: Opus con sufijo de fecha, se reconoce por prefijo: 1.00 + 0.50.
        $this->ejecucion($this->marcaBeta, 'claude-opus-5-20260101', 200_000, 20_000, 1.5, $hoy);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function ejecucion(Brand $marca, string $modelo, int $in, int $out, float $costo, CarbonImmutable $cuando, bool $simulado = false): void
    {
        $this->n++;
        $user = User::firstOrCreate(['email' => 'carga@test.local'], ['name' => 'Carga', 'password' => 'secreto-de-prueba', 'is_active' => true]);
        $sub = Submission::create(['brand_id' => $marca->id, 'user_id' => $user->id, 'channel' => 'instagram_post']);
        $asset = Asset::create([
            'submission_id' => $sub->id, 'brand_id' => $marca->id, 'original_filename' => "p{$this->n}.png",
            'storage_disk' => 'local', 'storage_path' => "piezas/p{$this->n}.png", 'file_hash' => hash('sha256', (string) $this->n),
            'mime_type' => 'image/png', 'file_size' => 1, 'width' => 1, 'height' => 1, 'extracted_palette' => [],
        ]);

        $run = new ValidationRun;
        $run->forceFill([
            'asset_id' => $asset->id, 'brand_id' => $marca->id, 'status' => 'completed',
            'model_identifier' => $modelo, 'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => $costo,
            'deterministic_results' => $simulado ? ['ai_simulated' => true] : ['coverage' => []],
            'created_at' => $cuando->utc(), 'updated_at' => $cuando->utc(),
        ])->save();
    }

    private function reporte(?array $clientes = null): array
    {
        return app(ReporteDeConsumo::class)->generar(
            CarbonImmutable::parse('2026-09-01', 'America/Lima'),
            CarbonImmutable::parse('2026-09-30', 'America/Lima'),
            [$this->marcaAlfa->id, $this->marcaBeta->id],
            $clientes,
        );
    }

    private function cliente(array $r, string $nombre): array
    {
        return collect($r['clientes'])->firstWhere('cliente', $nombre);
    }

    public function test_tokens_exactos_y_costo_recalculado_con_tarifa_vigente(): void
    {
        $alfa = $this->cliente($this->reporte(), 'Alfa');

        $this->assertSame(2, $alfa['llamadas']);            // excluye simulada, sin tokens y fuera de rango
        $this->assertSame(1_001_000, $alfa['entrada']);
        $this->assertSame(101_000, $alfa['salida']);
        $this->assertEqualsWithDelta(3.0, $alfa['costo'], 1e-9);
        $this->assertEqualsWithDelta(4.5, $alfa['registrado'], 1e-9);
        $this->assertTrue($alfa['costo_incompleto']);
        $this->assertNull($alfa['promedio'], 'Con costo parcial no se da promedio.');
    }

    public function test_modelo_sin_tarifa_se_informa_y_no_se_valoriza_en_cero(): void
    {
        $r = $this->reporte();
        $modelo = collect($this->cliente($r, 'Alfa')['modelos'])->firstWhere('nombre', 'modelo-raro');

        $this->assertSame(['modelo-raro'], $r['modelos_sin_tarifa']);
        $this->assertNull($modelo['costo']);
        $this->assertSame(2_000, $modelo['entrada'] + $modelo['salida']);
    }

    public function test_modelo_con_sufijo_de_fecha_usa_la_tarifa_de_su_familia(): void
    {
        $beta = $this->cliente($this->reporte(), 'Beta');

        $this->assertEqualsWithDelta(1.5, $beta['costo'], 1e-9);
        $this->assertFalse($beta['costo_incompleto']);
        $this->assertEqualsWithDelta(1.5, $beta['promedio'], 1e-9);
    }

    public function test_totales_y_filtro_por_cliente(): void
    {
        $r = $this->reporte();
        $this->assertSame(3, $r['total']['llamadas']);
        $this->assertEqualsWithDelta(4.5, $r['total']['costo'], 1e-9);
        $this->assertTrue($r['total']['costo_incompleto']);

        $soloBeta = $this->reporte([$this->beta->id]);
        $this->assertCount(1, $soloBeta['clientes']);
        $this->assertSame('Beta', $soloBeta['clientes'][0]['cliente']);
    }

    public function test_periodo_vacio_da_cero_no_sin_tarifa(): void
    {
        $r = app(ReporteDeConsumo::class)->generar(
            CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-01-31'), [$this->marcaAlfa->id],
        );

        $this->assertSame([], $r['clientes']);
        $this->assertSame(0.0, $r['total']['costo']);
    }

    public function test_sin_permiso_de_auditoria_no_entra(): void
    {
        $u = User::create(['name' => 'Up', 'email' => 'up@test.local', 'password' => 'secreto-de-prueba', 'is_active' => true]);
        $u->assignRole('uploader');

        $this->actingAs($u)->get(ConsumoIa::getUrl())->assertForbidden();
    }

    public function test_cada_usuario_ve_solo_sus_clientes_aunque_manipule_el_filtro(): void
    {
        $equipo = Team::create(['name' => 'Equipo Alfa', 'slug' => 'equipo-alfa', 'is_active' => true]);
        $equipo->clients()->attach($this->alfa->id);

        $u = User::create(['name' => 'Aud', 'email' => 'aud@test.local', 'password' => 'secreto-de-prueba', 'is_active' => true]);
        $u->assignRole('client_admin');
        $u->teams()->attach($equipo->id);
        $u->forgetAccessCache();

        Livewire::actingAs($u)->test(ConsumoIa::class)
            ->set('desde', '2026-09-01')->set('hasta', '2026-09-30')
            ->assertSee('Marca Alfa')
            ->assertDontSee('Marca Beta')
            ->set('cliente', (string) $this->beta->id)   // id ajeno: se ignora
            ->assertDontSee('Marca Beta')
            ->call('exportar')
            ->assertFileDownloaded('consumo-ia_2026-09-01_2026-09-30.csv');

        $this->assertDatabaseHas('audit_logs', ['action' => 'consumo.exported', 'user_id' => $u->id]);
    }

    public function test_super_admin_ve_la_pagina(): void
    {
        $u = User::create(['name' => 'Root', 'email' => 'root@test.local', 'password' => 'secreto-de-prueba', 'is_active' => true]);
        $u->assignRole('super_admin');

        $this->actingAs($u)->get(ConsumoIa::getUrl())
            ->assertOk()
            ->assertSee('Consumo de IA')
            ->assertSee('Alfa')
            ->assertSee('Beta');
    }
}
