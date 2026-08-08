<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Exceptions\ImmutableRecordException;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Client;
use App\Models\RuleSet;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica que la trazabilidad sea una restriccion real del sistema y no una
 * intencion documentada. Cubre dos capas: la inmutabilidad a nivel de modelo
 * y las llaves foraneas que protegen la cadena de evidencia.
 */
class ImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function cadenaDeEvidencia(): ValidationRun
    {
        $client = Client::create(['name' => 'Gloria', 'slug' => 'gloria']);

        $brand = Brand::create([
            'client_id' => $client->id,
            'name' => 'Pro',
            'slug' => 'pro',
        ]);

        $user = User::create([
            'name' => 'Tester',
            'email' => 'tester@validador.test',
            'password' => 'password',
        ]);

        $ruleSet = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand,
            'owner_id' => $brand->id,
            'client_id' => $client->id,
            'version' => 1,
            'name' => 'Pro v1',
            'status' => RuleSetStatus::Published,
        ]);

        $submission = Submission::create([
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'channel' => 'instagram_post',
        ]);

        $asset = Asset::create([
            'submission_id' => $submission->id,
            'brand_id' => $brand->id,
            'original_filename' => 'pieza.jpg',
            'storage_disk' => 'local',
            'storage_path' => 'piezas/pieza.jpg',
            'file_hash' => str_repeat('a', 64),
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'width' => 1080,
            'height' => 1080,
        ]);

        return ValidationRun::create([
            'asset_id' => $asset->id,
            'brand_id' => $brand->id,
            'brand_rule_set_id' => $ruleSet->id,
            'resolution_hash' => str_repeat('b', 64),
            'status' => 'pending',
        ]);
    }

    public function test_impide_modificar_un_registro_de_bitacora(): void
    {
        $log = AuditLog::create(['action' => 'test.created']);

        $this->expectException(ImmutableRecordException::class);

        $log->update(['action' => 'alterado']);
    }

    public function test_impide_eliminar_un_registro_de_bitacora(): void
    {
        $log = AuditLog::create(['action' => 'test.created']);

        $this->expectException(ImmutableRecordException::class);

        $log->delete();
    }

    public function test_la_bitacora_no_tiene_columna_de_actualizacion(): void
    {
        $log = AuditLog::create(['action' => 'test.created']);

        $this->assertNull(
            $log->updated_at,
            'Un registro de bitacora que se puede actualizar no es una bitacora'
        );
    }

    public function test_permite_que_una_validacion_avance_de_estado(): void
    {
        $run = $this->cadenaDeEvidencia();

        $run->update(['status' => 'completed', 'finished_at' => now()]);

        $this->assertSame('completed', $run->fresh()->status->value);
    }

    public function test_impide_reasignar_la_validacion_a_otra_pieza(): void
    {
        $run = $this->cadenaDeEvidencia();

        $this->expectException(ImmutableRecordException::class);

        $run->update(['asset_id' => 999]);
    }

    public function test_impide_alterar_la_huella_del_conjunto_de_reglas(): void
    {
        $run = $this->cadenaDeEvidencia();

        $this->expectException(ImmutableRecordException::class);

        $run->update(['resolution_hash' => str_repeat('c', 64)]);
    }

    public function test_impide_eliminar_una_validacion(): void
    {
        $run = $this->cadenaDeEvidencia();

        $this->expectException(ImmutableRecordException::class);

        $run->delete();
    }

    public function test_no_se_puede_borrar_el_conjunto_de_reglas_usado(): void
    {
        $run = $this->cadenaDeEvidencia();

        $this->expectException(QueryException::class);

        RuleSet::withoutEvents(fn () => $run->brandRuleSet->forceDelete());
    }

    public function test_no_se_puede_borrar_la_marca_con_evidencia_asociada(): void
    {
        $run = $this->cadenaDeEvidencia();

        $this->expectException(QueryException::class);

        Brand::withoutEvents(fn () => $run->asset->brand->forceDelete());
    }

    public function test_no_se_puede_borrar_el_cliente_con_evidencia_asociada(): void
    {
        $run = $this->cadenaDeEvidencia();

        $this->expectException(QueryException::class);

        Client::withoutEvents(fn () => $run->asset->brand->client->forceDelete());
    }

    public function test_no_se_puede_borrar_el_usuario_que_cargo_la_pieza(): void
    {
        $run = $this->cadenaDeEvidencia();

        $this->expectException(QueryException::class);

        User::withoutEvents(fn () => $run->asset->submission->user->forceDelete());
    }

    public function test_una_marca_sin_evidencia_si_se_puede_borrar(): void
    {
        $client = Client::create(['name' => 'Alicorp', 'slug' => 'alicorp']);
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Libre', 'slug' => 'libre']);

        $brand->forceDelete();

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }
}
