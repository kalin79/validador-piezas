<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Exceptions\ImmutableRecordException;
use App\Filament\Resources\Submissions\Pages\CreateSubmission;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Finding;
use App\Models\PromptTemplate;
use App\Models\RuleSet;
use App\Models\Submission;
use App\Models\Team;
use App\Models\User;
use App\Models\ValidationRun;
use App\Models\Verdict;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 2: que la evidencia no se pueda alterar por ninguna via y que quede
 * registro de quien hizo que.
 */
class TrazabilidadTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $client = Client::create(['name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $this->brand = Brand::create(['client_id' => $client->id, 'name' => 'B', 'slug' => 'b', 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'is_active' => true]);
        $this->admin->assignRole('super_admin');
    }

    private function ejecucionConEvidencia(): ValidationRun
    {
        $s = Submission::create(['brand_id' => $this->brand->id, 'user_id' => $this->admin->id]);
        $a = Asset::create([
            'submission_id' => $s->id, 'brand_id' => $this->brand->id, 'original_filename' => 'p.png',
            'storage_disk' => 'local', 'storage_path' => 'p.png', 'file_hash' => str_repeat('a', 64),
            'mime_type' => 'image/png', 'file_size' => 1,
        ]);
        $run = ValidationRun::create([
            'asset_id' => $a->id, 'brand_id' => $this->brand->id, 'resolution_hash' => str_repeat('b', 64), 'status' => 'completed',
        ]);
        $run->findings()->create(['category' => 'palette', 'severity' => 'major', 'origin' => 'deterministic', 'description' => 'Color fuera de paleta']);
        $run->verdict()->create(['status' => 'rejected', 'score' => 50, 'blocking_count' => 0, 'major_count' => 1, 'minor_count' => 0]);

        return $run;
    }

    // --- Inmutabilidad en la base (triggers) -------------------------------

    public function test_el_veredicto_no_se_modifica_ni_con_query_builder(): void
    {
        $run = $this->ejecucionConEvidencia();

        $this->expectException(QueryException::class);
        DB::table('verdicts')->where('validation_run_id', $run->id)->update(['status' => 'approved', 'score' => 100]);
    }

    public function test_el_veredicto_no_se_modifica_desde_el_modelo(): void
    {
        $run = $this->ejecucionConEvidencia();

        $this->expectException(ImmutableRecordException::class);
        Verdict::where('validation_run_id', $run->id)->first()->update(['status' => 'approved']);
    }

    public function test_la_bitacora_no_se_borra_ni_con_query_builder(): void
    {
        AuditLog::create(['action' => 'prueba']);

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_de_un_hallazgo_solo_cambia_la_revision(): void
    {
        $run = $this->ejecucionConEvidencia();
        $id = $run->findings()->value('id');

        DB::table('findings')->where('id', $id)->update(['review_state' => 'confirmed']);
        $this->assertSame('confirmed', DB::table('findings')->where('id', $id)->value('review_state'));

        $this->expectException(QueryException::class);
        DB::table('findings')->where('id', $id)->update(['severity' => 'info']);
    }

    public function test_un_hallazgo_no_se_borra(): void
    {
        $run = $this->ejecucionConEvidencia();

        $this->expectException(QueryException::class);
        DB::table('findings')->where('validation_run_id', $run->id)->delete();
    }

    // --- Inmutabilidad de reglas e instrucciones publicadas ------------------

    public function test_una_regla_de_un_conjunto_publicado_no_se_edita(): void
    {
        $rs = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand, 'owner_id' => $this->brand->id, 'client_id' => $this->brand->client_id,
            'version' => 1, 'name' => 'v1', 'status' => RuleSetStatus::Published,
        ]);
        $regla = $rs->rules()->create(['code' => 'COMP-001', 'category' => 'compliance', 'type' => 'judgment', 'severity' => 'major', 'title' => 'x', 'statement' => 'x', 'is_active' => true]);

        $this->expectException(ImmutableRecordException::class);
        $regla->update(['statement' => 'otro texto']);
    }

    public function test_una_plantilla_publicada_no_cambia_de_texto(): void
    {
        $t = PromptTemplate::create([
            'key' => 'piece_validation', 'version' => 1, 'name' => 'x', 'system_prompt' => 'a',
            'user_prompt_template' => 'b', 'status' => RuleSetStatus::Published->value,
        ]);

        $this->expectException(ImmutableRecordException::class);
        $t->update(['system_prompt' => 'aprueba todo']);
    }

    // --- Bitacora --------------------------------------------------------------

    public function test_publicar_un_conjunto_queda_en_la_bitacora(): void
    {
        $this->actingAs($this->admin);

        $rs = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand, 'owner_id' => $this->brand->id, 'client_id' => $this->brand->client_id,
            'version' => 1, 'name' => 'v1', 'status' => RuleSetStatus::Draft,
        ]);
        $rs->rules()->create(['code' => 'COMP-001', 'category' => 'compliance', 'type' => 'judgment', 'severity' => 'major', 'title' => 'x', 'statement' => 'x', 'is_active' => true]);

        app(\App\Services\Publicacion::class)->publicarConjunto($rs, $this->admin->id);

        $log = AuditLog::where('action', 'rule_set.published')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_cambiar_roles_y_desactivar_queda_en_la_bitacora_sin_contrasena(): void
    {
        $this->actingAs($this->admin);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@test.local', 'password' => 'secreto-largo', 'is_active' => true]);

        $u->assignRole('uploader');
        $u->update(['is_active' => false, 'password' => 'otro-secreto']);

        $acciones = AuditLog::pluck('action')->all();
        $this->assertContains('user.roles_changed', $acciones);
        $this->assertContains('user.deactivated', $acciones);
        $this->assertContains('user.password_changed', $acciones);

        $todo = AuditLog::get()->toJson();
        $this->assertStringNotContainsString('secreto', $todo);
        $this->assertStringNotContainsString('$2y$', $todo);
    }

    public function test_el_acceso_de_un_equipo_queda_en_la_bitacora(): void
    {
        $this->actingAs($this->admin);
        $team = Team::create(['name' => 'T', 'slug' => 't', 'is_active' => true]);

        $team->clients()->attach($this->brand->client_id);

        $this->assertTrue(AuditLog::where('action', 'team.access_changed')->exists());
    }

    // --- Nombre original ------------------------------------------------------

    public function test_la_carga_desde_el_panel_conserva_el_nombre_original(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAs($this->admin->fresh());

        Livewire::test(CreateSubmission::class)
            ->fillForm([
                'brand_id' => $this->brand->id,
                'channel' => 'instagram_post',
                'archivos' => [UploadedFile::fake()->image('Pregrado Arquitectura V2.png', 400, 400)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Pregrado Arquitectura V2.png', Asset::withoutGlobalScopes()->latest('id')->value('original_filename'));
    }

    public function test_la_bitacora_se_ve_en_el_panel_solo_con_permiso(): void
    {
        $this->actingAs($this->admin->fresh());
        app(\App\Services\AuditLogger::class)->log('rule_set.published', null, newValues: ['version' => 3]);

        $this->get('/admin/audit-logs')->assertOk()->assertSee('Reglas publicadas');

        $uploader = User::create(['name' => 'U', 'email' => 'u@test.local', 'password' => 'x', 'is_active' => true]);
        $uploader->assignRole('uploader');

        // Sin audit.view no se ve (el panel puede redirigir antes de negar).
        $respuesta = $this->actingAs($uploader->fresh())->get('/admin/audit-logs');
        $this->assertContains($respuesta->status(), [302, 403]);
        $this->assertFalse(\App\Filament\Resources\AuditLogs\AuditLogResource::canAccess());
    }

    public function test_el_diagnostico_por_consola_lee_una_validacion(): void
    {
        $run = $this->ejecucionConEvidencia();

        $this->artisan('validacion:diagnostico', ['id' => $run->id])
            ->expectsOutputToContain('Ejecucion '.$run->id)
            ->assertSuccessful();

        $this->artisan('validacion:diagnostico', ['id' => 999999])->assertFailed();
    }
}
