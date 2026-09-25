<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DirectorReviewStatus;
use App\Enums\VerdictStatus;
use App\Filament\Pages\BandejaDirector;
use App\Filament\Resources\Submissions\Pages\EditSubmission;
use App\Filament\Resources\Submissions\RelationManagers\AssetsRelationManager;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Client;
use App\Models\DirectorReview;
use App\Models\HumanReview;
use App\Models\Submission;
use App\Models\Team;
use App\Models\User;
use App\Models\ValidationRun;
use App\Models\Verdict;
use App\Notifications\DecisionDelDirector;
use App\Notifications\PiezaEnviadaAlDirector;
use App\Services\Director\EnvioAlDirector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Flujo del director: quien puede enviar, que se puede enviar, quien decide
 * y que queda registrado.
 */
class DirectorFlujoTest extends TestCase
{
    use RefreshDatabase;

    private Brand $marca;

    private Brand $marcaBeta;

    private User $disenador;

    private User $director;

    private User $directorBeta;

    private User $revisor;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Notification::fake();

        $alfa = Client::create(['name' => 'Alfa', 'slug' => 'alfa', 'is_active' => true]);
        $beta = Client::create(['name' => 'Beta', 'slug' => 'beta', 'is_active' => true]);
        $this->marca = Brand::create(['client_id' => $alfa->id, 'name' => 'Marca Alfa', 'slug' => 'ma', 'is_active' => true]);
        $this->marcaBeta = Brand::create(['client_id' => $beta->id, 'name' => 'Marca Beta', 'slug' => 'mb', 'is_active' => true]);

        $equipoAlfa = Team::create(['name' => 'Alfa', 'slug' => 'eq-alfa', 'is_active' => true]);
        $equipoAlfa->clients()->attach($alfa->id);
        $equipoBeta = Team::create(['name' => 'Beta', 'slug' => 'eq-beta', 'is_active' => true]);
        $equipoBeta->clients()->attach($beta->id);

        $this->disenador = $this->usuario('dis@test.local', 'uploader', $equipoAlfa);
        $this->director = $this->usuario('dir@test.local', 'director', $equipoAlfa);
        $this->directorBeta = $this->usuario('dirb@test.local', 'director', $equipoBeta);
        $this->revisor = $this->usuario('rev@test.local', 'reviewer', $equipoAlfa);
    }

    private function usuario(string $email, string $rol, Team $equipo): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secreto-de-prueba', 'is_active' => true]);
        $u->assignRole($rol);
        $u->teams()->attach($equipo->id);
        $u->forgetAccessCache();

        return $u;
    }

    private function pieza(VerdictStatus $veredicto, ?Brand $marca = null, float $puntaje = 92): Asset
    {
        $marca ??= $this->marca;
        $this->n++;

        $sub = Submission::create(['brand_id' => $marca->id, 'user_id' => $this->disenador->id, 'channel' => 'instagram_post', 'campaign' => 'Campana']);
        $asset = Asset::create([
            'submission_id' => $sub->id, 'brand_id' => $marca->id, 'original_filename' => "pieza{$this->n}.png",
            'storage_disk' => 'local', 'storage_path' => "piezas/p{$this->n}.png", 'file_hash' => hash('sha256', (string) $this->n),
            'mime_type' => 'image/png', 'file_size' => 1, 'width' => 1080, 'height' => 1080, 'extracted_palette' => [],
        ]);
        $this->validar($asset, $veredicto, $puntaje);

        return $asset;
    }

    private function validar(Asset $asset, VerdictStatus $veredicto, float $puntaje = 92): ValidationRun
    {
        $run = ValidationRun::create([
            'asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'status' => 'completed',
            'model_identifier' => 'claude-sonnet-5', 'input_tokens' => 10, 'output_tokens' => 10,
        ]);
        Verdict::create(['validation_run_id' => $run->id, 'status' => $veredicto->value, 'score' => $puntaje]);

        return $run;
    }

    private function servicio(): EnvioAlDirector
    {
        return app(EnvioAlDirector::class);
    }

    public function test_pieza_aprobada_se_envia_y_avisa_solo_a_los_directores_de_la_marca(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);

        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador, 'Sale el lunes');

        $this->assertSame(DirectorReviewStatus::Pending, $envio->status);
        $this->assertSame(VerdictStatus::Approved, $envio->verdict_at_send);
        $this->assertEqualsWithDelta(92.0, $envio->score_at_send, 0.001);
        $this->assertSame('Sale el lunes', $envio->sender_note);

        Notification::assertSentTo($this->director, PiezaEnviadaAlDirector::class);
        Notification::assertNotSentTo($this->directorBeta, PiezaEnviadaAlDirector::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'director.sent', 'user_id' => $this->disenador->id]);
    }

    public function test_rechazada_requiere_revision_y_sin_evaluar_no_se_envian(): void
    {
        foreach ([VerdictStatus::Rejected, VerdictStatus::RequiresReview, VerdictStatus::NotEvaluated] as $v) {
            $motivo = $this->servicio()->motivoParaNoEnviar($this->pieza($v), $this->disenador);
            $this->assertNotNull($motivo, $v->value.' no deberia poder enviarse');
            $this->assertStringContainsString('Solo se envia una pieza aprobada', $motivo);
        }

        $this->assertNull($this->servicio()->motivoParaNoEnviar($this->pieza(VerdictStatus::ApprovedWithObservations), $this->disenador));
    }

    public function test_requiere_revision_aprobada_por_un_revisor_si_se_envia(): void
    {
        $asset = $this->pieza(VerdictStatus::RequiresReview);
        $run = $asset->latestRun()->first();

        HumanReview::create([
            'validation_run_id' => $run->id, 'reviewer_id' => $this->revisor->id,
            'machine_verdict' => VerdictStatus::RequiresReview->value, 'final_verdict' => VerdictStatus::Approved->value,
            'justification' => 'Leyenda legible en el original', 'finding_decisions' => [],
        ]);

        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);

        $this->assertSame(VerdictStatus::Approved, $envio->verdict_at_send);
        $this->assertTrue($envio->verdict_from_human);
    }

    public function test_solo_se_toma_la_ultima_validacion(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->validar($asset, VerdictStatus::Rejected, 30);

        $this->assertStringContainsString('Rechazado', (string) $this->servicio()->motivoParaNoEnviar($asset, $this->disenador));
    }

    public function test_no_hay_dos_envios_pendientes_de_la_misma_pieza(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->actingAs($this->disenador);
        $this->servicio()->enviar($asset, $this->disenador);

        $this->assertSame('Ya esta enviada y pendiente del director.', $this->servicio()->motivoParaNoEnviar($asset, $this->disenador));

        // Y la base lo impide aunque alguien salte el servicio.
        $this->expectException(QueryException::class);
        DirectorReview::create([
            'asset_id' => $asset->id, 'validation_run_id' => $asset->latestRun()->value('id'), 'brand_id' => $asset->brand_id,
            'sent_by' => $this->disenador->id, 'verdict_at_send' => 'approved', 'status' => 'pending',
        ]);
    }

    public function test_sin_director_asignado_no_se_envia(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->director->update(['is_active' => false]);

        $this->assertStringContainsString('no tiene ningun director', (string) $this->servicio()->motivoParaNoEnviar($asset, $this->disenador));
    }

    public function test_el_director_no_puede_enviar_ni_el_disenador_decidir(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->assertSame('Tu rol no puede enviar piezas al director.', $this->servicio()->motivoParaNoEnviar($asset, $this->director));

        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);

        $this->expectException(RuntimeException::class);
        $this->servicio()->aprobar($envio->fresh(), $this->disenador);
    }

    public function test_director_de_otro_cliente_no_decide(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);

        $this->expectExceptionMessage('No tienes acceso a esta marca.');
        $this->servicio()->aprobar($envio, $this->directorBeta);
    }

    public function test_aprobar_registra_avisa_y_queda_inmutable(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);

        $this->actingAs($this->director);
        $this->servicio()->aprobar($envio, $this->director, 'Lista para publicar');

        $envio->refresh();
        $this->assertSame(DirectorReviewStatus::Approved, $envio->status);
        $this->assertSame($this->director->id, (int) $envio->decided_by);
        Notification::assertSentTo($this->disenador, DecisionDelDirector::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'director.approved', 'user_id' => $this->director->id]);

        $this->assertSame('El director ya aprobo esta pieza.', $this->servicio()->motivoParaNoEnviar($asset, $this->disenador));

        $this->expectException(LogicException::class);
        $envio->update(['decision_comment' => 'cambiado']);
    }

    public function test_devolver_exige_comentario_y_permite_reenviar_una_version_revalidada(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);

        $this->actingAs($this->director);

        try {
            $this->servicio()->devolver($envio, $this->director, 'no');
            $this->fail('Devolver sin motivo deberia fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('escribe que hay que corregir', $e->getMessage());
        }

        $this->servicio()->devolver($envio, $this->director, 'El logo esta pegado al borde, dale aire.');
        $this->assertSame(DirectorReviewStatus::Returned, $envio->fresh()->status);

        // Misma validacion: no se reenvia igual.
        $this->assertStringContainsString('la devolvio', (string) $this->servicio()->motivoParaNoEnviar($asset, $this->disenador));

        // Con una validacion nueva aprobada si.
        $this->validar($asset, VerdictStatus::Approved);
        $this->assertNull($this->servicio()->motivoParaNoEnviar($asset, $this->disenador));
    }

    public function test_no_se_aprueba_si_una_revalidacion_posterior_ya_no_aprueba(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);
        $this->validar($asset, VerdictStatus::Rejected, 20);

        $this->assertStringContainsString('Rechazado', (string) $this->servicio()->motivoParaNoDecidir($envio->fresh('asset.submission'), $this->director, true));
        $this->assertNull($this->servicio()->motivoParaNoDecidir($envio->fresh('asset.submission'), $this->director, false));
    }

    public function test_quien_envio_puede_retirar_y_otro_no(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);

        try {
            $this->servicio()->retirar($envio, $this->revisor);
            $this->fail('Otro usuario no deberia retirar');
        } catch (RuntimeException) {
        }

        $this->servicio()->retirar($envio, $this->disenador);
        $this->assertSame(DirectorReviewStatus::Withdrawn, $envio->fresh()->status);
        $this->assertNull($this->servicio()->motivoParaNoEnviar($asset, $this->disenador));
    }

    public function test_bandeja_muestra_solo_lo_propio_y_aprueba_desde_la_pantalla(): void
    {
        $alfa = $this->pieza(VerdictStatus::Approved);
        $beta = $this->pieza(VerdictStatus::Approved, $this->marcaBeta);

        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($alfa, $this->disenador, 'Nota visible');
        DirectorReview::create([
            'asset_id' => $beta->id, 'validation_run_id' => ValidationRun::withoutGlobalScopes()->where('asset_id', $beta->id)->value('id'), 'brand_id' => $beta->brand_id,
            'sent_by' => $this->disenador->id, 'verdict_at_send' => 'approved', 'status' => 'pending',
        ]);

        Livewire::actingAs($this->director)->test(BandejaDirector::class)
            ->assertSee($alfa->original_filename)
            ->assertSee('Nota visible')
            ->assertDontSee($beta->original_filename)
            ->call('alternar', $envio->public_id)
            ->set("comentarios.{$envio->public_id}", 'Perfecta')
            ->call('aprobar', $envio->public_id)
            ->assertSee('No hay piezas esperando tu decision.');

        $this->assertSame(DirectorReviewStatus::Approved, $envio->fresh()->status);
        $this->assertSame('Perfecta', $envio->fresh()->decision_comment);
    }

    // Dos pruebas y no una: AuthenticateSession cierra la sesion si cambia el
    // usuario entre peticiones del mismo test.
    public function test_el_disenador_no_entra_a_la_bandeja(): void
    {
        $this->actingAs($this->disenador)->get(BandejaDirector::getUrl())->assertForbidden();
    }

    public function test_el_director_entra_a_la_bandeja(): void
    {
        $this->actingAs($this->director)->get(BandejaDirector::getUrl())->assertOk()->assertSee('Bandeja del director');
    }

    public function test_los_envios_no_se_borran(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $this->actingAs($this->disenador);
        $envio = $this->servicio()->enviar($asset, $this->disenador);

        $this->expectException(LogicException::class);
        $envio->delete();
    }

    public function test_boton_enviar_desde_la_carga(): void
    {
        $asset = $this->pieza(VerdictStatus::Approved);
        $rechazada = $this->pieza(VerdictStatus::Rejected);

        Livewire::actingAs($this->disenador)
            ->test(AssetsRelationManager::class, ['ownerRecord' => $asset->submission()->first(), 'pageClass' => EditSubmission::class])
            ->assertTableActionEnabled('enviar_director', $asset)
            ->callTableAction('enviar_director', $asset, ['nota' => 'Version final'])
            ->assertTableActionDisabled('enviar_director', $asset)
            ->assertSee('Pendiente del director');

        Livewire::actingAs($this->disenador)
            ->test(AssetsRelationManager::class, ['ownerRecord' => $rechazada->submission()->first(), 'pageClass' => EditSubmission::class])
            ->assertTableActionDisabled('enviar_director', $rechazada);

        $this->assertSame('Version final', DirectorReview::query()->where('asset_id', $asset->id)->value('sender_note'));
    }
}
