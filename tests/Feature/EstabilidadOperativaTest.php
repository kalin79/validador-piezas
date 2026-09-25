<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Enums\ValidationStatus;
use App\Enums\VerdictStatus;
use App\Exceptions\ImmutableRecordException;
use App\Jobs\RunValidation;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Client;
use App\Models\HumanReview;
use App\Models\RuleSet;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationRun;
use App\Services\Publicacion;
use App\Services\Review\ReviewRecorder;
use App\Support\Image\LimiteDePixeles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 1 de la auditoria: que la operacion real (colas, concurrencia, procesos
 * que mueren) no deje el sistema en un estado que mienta.
 */
class EstabilidadOperativaTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Brand $brand;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->client = Client::create(['name' => 'Gloria', 'slug' => 'gloria']);
        $this->brand = Brand::create(['client_id' => $this->client->id, 'name' => 'Pro', 'slug' => 'pro']);
        $this->autor = User::create(['name' => 'Autor', 'email' => 'autor@test.local', 'password' => 'x', 'is_active' => true]);
    }

    private function conjunto(int $version, RuleSetStatus $estado, bool $conRegla = true): RuleSet
    {
        $rs = RuleSet::withoutEvents(fn () => RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand,
            'owner_id' => $this->brand->id,
            'client_id' => $this->client->id,
            'version' => $version,
            'name' => "v{$version}",
            'status' => $estado,
        ]));

        if ($conRegla) {
            $rs->rules()->create([
                'code' => "COMP-00{$version}", 'category' => 'compliance', 'type' => 'judgment',
                'severity' => 'major', 'title' => 'x', 'statement' => 'x', 'is_active' => true,
            ]);
        }

        return $rs;
    }

    private function ejecucion(string $estado = 'running', ?VerdictStatus $veredicto = null): ValidationRun
    {
        $submission = Submission::create(['brand_id' => $this->brand->id, 'user_id' => $this->autor->id]);

        $asset = Asset::create([
            'submission_id' => $submission->id,
            'brand_id' => $this->brand->id,
            'original_filename' => 'pieza.png',
            'storage_disk' => 'local',
            'storage_path' => 'piezas/pieza.png',
            'file_hash' => str_repeat('a', 64),
            'mime_type' => 'image/png',
            'file_size' => 10,
        ]);

        $run = ValidationRun::create([
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
            'resolution_hash' => str_repeat('b', 64),
            'status' => $estado,
            'started_at' => now(),
        ]);

        if ($veredicto !== null) {
            $run->verdict()->create(['status' => $veredicto->value, 'score' => 80, 'blocking_count' => 0, 'major_count' => 0, 'minor_count' => 0]);
        }

        return $run;
    }

    private function revisor(string $rol = 'reviewer'): User
    {
        $u = User::create(['name' => $rol, 'email' => "{$rol}@test.local", 'password' => 'x', 'is_active' => true]);
        $u->assignRole($rol);

        return $u;
    }

    // --- Publicacion ---------------------------------------------------------

    public function test_publicar_deja_exactamente_una_version_vigente(): void
    {
        $v1 = $this->conjunto(1, RuleSetStatus::Published);
        $v2 = $this->conjunto(2, RuleSetStatus::Draft);
        $v3 = $this->conjunto(3, RuleSetStatus::Draft);

        $servicio = app(Publicacion::class);
        $servicio->publicarConjunto($v2, null);
        $servicio->publicarConjunto($v3, null);

        $vigentes = RuleSet::query()->where('owner_id', $this->brand->id)->where('status', 'published')->pluck('id');

        $this->assertSame([$v3->id], $vigentes->all());
        $this->assertSame(RuleSetStatus::Retired, $v1->fresh()->status);
    }

    public function test_no_se_publica_dos_veces_la_misma_version(): void
    {
        $v1 = $this->conjunto(1, RuleSetStatus::Draft);
        $copiaVieja = RuleSet::find($v1->id);

        app(Publicacion::class)->publicarConjunto($v1, null);

        $this->expectException(RuntimeException::class);
        app(Publicacion::class)->publicarConjunto($copiaVieja, null);
    }

    public function test_no_se_publica_un_conjunto_vacio(): void
    {
        $v1 = $this->conjunto(1, RuleSetStatus::Draft, conRegla: false);

        $this->expectException(RuntimeException::class);
        app(Publicacion::class)->publicarConjunto($v1, null);
    }

    // --- Revision humana -----------------------------------------------------

    public function test_nadie_revisa_su_propia_pieza(): void
    {
        $this->autor->assignRole('reviewer');
        $run = $this->ejecucion('completed', VerdictStatus::Rejected);

        $this->expectExceptionMessage('No puedes revisar una pieza que subiste');
        app(ReviewRecorder::class)->record($run, $this->autor->id, VerdictStatus::Rejected, []);
    }

    public function test_una_ejecucion_se_revisa_una_sola_vez(): void
    {
        $run = $this->ejecucion('completed', VerdictStatus::Rejected);
        $a = $this->revisor('reviewer');
        $b = $this->revisor('brand_admin');

        app(ReviewRecorder::class)->record($run, $a->id, VerdictStatus::Rejected, []);

        $this->expectExceptionMessage('ya fue revisada');
        app(ReviewRecorder::class)->record($run->fresh(), $b->id, VerdictStatus::Rejected, []);
    }

    public function test_la_base_impide_una_segunda_revision_aunque_se_salte_el_servicio(): void
    {
        $run = $this->ejecucion('completed', VerdictStatus::Rejected);
        $a = $this->revisor('reviewer');

        $fila = ['validation_run_id' => $run->id, 'reviewer_id' => $a->id, 'machine_verdict' => 'rejected', 'final_verdict' => 'rejected', 'finding_decisions' => []];
        HumanReview::create($fila);

        $this->expectException(QueryException::class);
        HumanReview::create($fila);
    }

    public function test_anular_el_veredicto_exige_permiso_de_anulacion(): void
    {
        // Rol ad hoc: puede revisar pero no anular.
        $rol = \Spatie\Permission\Models\Role::findOrCreate('revisor_limitado');
        $rol->syncPermissions(['review.perform']);
        $limitado = $this->revisor('revisor_limitado');

        $run = $this->ejecucion('completed', VerdictStatus::Rejected);

        $this->expectExceptionMessage('No tienes permiso para cambiar el veredicto');
        app(ReviewRecorder::class)->record($run, $limitado->id, VerdictStatus::Approved, [], justificacion: 'me parece bien');
    }

    public function test_resolver_un_requiere_revision_no_cuenta_como_anulacion(): void
    {
        $rol = \Spatie\Permission\Models\Role::findOrCreate('revisor_limitado');
        $rol->syncPermissions(['review.perform']);
        $limitado = $this->revisor('revisor_limitado');

        $run = $this->ejecucion('completed', VerdictStatus::RequiresReview);

        $revision = app(ReviewRecorder::class)->record($run, $limitado->id, VerdictStatus::Approved, [], justificacion: 'Leyenda verificada a mano.');

        $this->assertSame(VerdictStatus::Approved, $revision->final_verdict);
    }

    // --- Cola y ejecuciones colgadas ------------------------------------------

    public function test_el_job_no_reintenta_ni_se_duplica(): void
    {
        $job = new RunValidation($this->ejecucion()->asset);

        $this->assertSame(1, $job->tries);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.database.retry_after'));
    }

    public function test_si_el_job_muere_la_ejecucion_queda_fallida_y_se_avisa(): void
    {
        $run = $this->ejecucion('running');

        (new RunValidation($run->asset, $this->autor->id))->failed(new RuntimeException('timeout'));

        $this->assertSame(ValidationStatus::Failed, $run->fresh()->status);
        $this->assertSame(1, $this->autor->notifications()->count());
    }

    public function test_las_ejecuciones_colgadas_se_cierran(): void
    {
        $vieja = $this->ejecucion('running');
        ValidationRun::query()->whereKey($vieja->id)->update(['started_at' => now()->subHour()]);
        $reciente = $this->ejecucion('running');

        $this->artisan('validaciones:cerrar-colgadas')->assertSuccessful();

        $this->assertSame(ValidationStatus::Failed, $vieja->fresh()->status);
        $this->assertSame(ValidationStatus::Running, $reciente->fresh()->status);
    }

    public function test_una_ejecucion_terminada_no_cambia_de_estado(): void
    {
        $run = $this->ejecucion('failed');

        $this->expectException(ImmutableRecordException::class);
        $run->update(['status' => ValidationStatus::Completed->value]);
    }

    // --- Imagenes enormes ------------------------------------------------------

    public function test_rechaza_imagenes_por_encima_del_tope_de_pixeles(): void
    {
        config(['filesystems.piezas_max_megapixeles' => 1]);

        $img = imagecreatetruecolor(1200, 1000);
        $ruta = tempnam(sys_get_temp_dir(), 'px').'.png';
        imagepng($img, $ruta);

        try {
            $this->expectExceptionMessage('megapixeles');
            LimiteDePixeles::verificar($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    // --- Integridad ------------------------------------------------------------

    public function test_integridad_avisa_una_vez_por_el_mismo_problema(): void
    {
        Storage::fake('local');
        $admin = $this->revisor('super_admin');
        $this->ejecucion('completed'); // su archivo no existe en disco

        $this->artisan('integridad:verificar --avisar')->assertFailed();
        $this->artisan('integridad:verificar --avisar')->assertFailed();

        $this->assertSame(1, $admin->notifications()->count());
    }
}
