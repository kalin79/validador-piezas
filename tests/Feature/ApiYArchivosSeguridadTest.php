<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Submission;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tokens de la API y archivos privados: los dos accesos que sobrevivian a la
 * baja de un usuario.
 */
class ApiYArchivosSeguridadTest extends TestCase
{
    use RefreshDatabase;

    private Brand $marcaAlfa;

    private Brand $marcaBeta;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $alfa = Client::create(['name' => 'Alfa', 'slug' => 'alfa', 'is_active' => true]);
        $beta = Client::create(['name' => 'Beta', 'slug' => 'beta', 'is_active' => true]);
        $this->marcaAlfa = Brand::create(['client_id' => $alfa->id, 'name' => 'MA', 'slug' => 'ma', 'is_active' => true]);
        $this->marcaBeta = Brand::create(['client_id' => $beta->id, 'name' => 'MB', 'slug' => 'mb', 'is_active' => true]);

        $equipo = Team::create(['name' => 'Alfa', 'slug' => 'alfa', 'is_active' => true]);
        $equipo->clients()->attach($alfa->id);

        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@alfa.test', 'password' => 'secreto-de-prueba', 'is_active' => true]);
        $this->ana->assignRole('uploader');
        $this->ana->teams()->attach($equipo->id);
        $this->ana->forgetAccessCache();
    }

    private function pieza(Brand $marca, User $duenio): Asset
    {
        Storage::fake('local');
        Storage::disk('local')->put('piezas/x.png', 'png-falso');

        $submission = Submission::withoutGlobalScopes()->create(['brand_id' => $marca->id, 'user_id' => $duenio->id]);

        return Asset::withoutGlobalScopes()->create([
            'submission_id' => $submission->id,
            'brand_id' => $marca->id,
            'original_filename' => 'x.png',
            'storage_disk' => 'local',
            'storage_path' => 'piezas/x.png',
            'file_hash' => hash('sha256', 'png-falso'),
            'mime_type' => 'image/png',
            'file_size' => 9,
        ]);
    }

    public function test_desactivar_al_usuario_revoca_sus_tokens(): void
    {
        $token = $this->ana->createToken('Plugin')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/yo')->assertOk();

        $this->ana->update(['is_active' => false]);

        $this->assertSame(0, $this->ana->tokens()->count());
    }

    public function test_un_token_de_usuario_inactivo_no_sirve_aunque_siga_en_la_base(): void
    {
        $token = $this->ana->createToken('Plugin')->plainTextToken;

        // Desactivado sin pasar por el observador (por ejemplo, un UPDATE directo).
        User::query()->whereKey($this->ana->id)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/yo')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/marcas')->assertForbidden();
    }

    public function test_sin_permiso_de_validar_el_token_no_valida(): void
    {
        $token = $this->ana->createToken('Plugin')->plainTextToken;
        $this->ana->syncRoles(['auditor']);

        $this->withToken($token)
            ->postJson('/api/v1/validaciones', ['brand' => 'alfa/ma'])
            ->assertStatus(422); // falla primero la validacion de la imagen

        $this->withToken($token)
            ->post('/api/v1/validaciones', [
                'brand' => 'alfa/ma',
                'image' => \Illuminate\Http\UploadedFile::fake()->image('p.png', 100, 100),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_los_tokens_vencen(): void
    {
        $this->assertNotNull(config('sanctum.expiration'));
    }

    public function test_la_pieza_no_se_sirve_sin_sesion(): void
    {
        $pieza = $this->pieza($this->marcaAlfa, $this->ana);

        $this->get($pieza->url())->assertRedirect();
    }

    public function test_la_pieza_se_sirve_a_su_duenio_con_cabeceras_seguras(): void
    {
        $pieza = $this->pieza($this->marcaAlfa, $this->ana);

        $this->actingAs($this->ana)
            ->get($pieza->url())
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_la_pieza_de_otro_cliente_no_se_sirve(): void
    {
        $bruno = User::create(['name' => 'Bruno', 'email' => 'b@beta.test', 'password' => 'x', 'is_active' => true]);
        $pieza = $this->pieza($this->marcaBeta, $bruno);

        $this->actingAs($this->ana)
            ->get(route('archivos.pieza', $pieza->public_id))
            ->assertNotFound();
    }

    public function test_la_raiz_lleva_al_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
