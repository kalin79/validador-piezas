<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Palette;
use App\Models\RuleSet;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La garantia que sostiene el modelo de negocio.
 *
 * El sistema se vende por confidencialidad: las piezas sin publicar y los
 * manuales de marca de un cliente no pueden verse desde otro. Antes de estas
 * pruebas esa garantia era una intencion; ahora falla la suite si se rompe.
 *
 * Escenario: dos clientes que compiten. Ana trabaja para Alfa, Bruno para
 * Beta, y ninguno de los dos tiene rol global.
 */
class AislamientoEntreClientesTest extends TestCase
{
    use RefreshDatabase;

    private Client $alfa;

    private Client $beta;

    private Brand $marcaAlfa;

    private Brand $marcaBeta;

    private User $ana;

    private User $bruno;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->alfa = Client::create(['name' => 'Alfa', 'slug' => 'alfa', 'is_active' => true]);
        $this->beta = Client::create(['name' => 'Beta', 'slug' => 'beta', 'is_active' => true]);

        $this->marcaAlfa = Brand::create([
            'client_id' => $this->alfa->id,
            'name' => 'Marca Alfa',
            'slug' => 'marca-alfa',
            'is_active' => true,
        ]);

        $this->marcaBeta = Brand::create([
            'client_id' => $this->beta->id,
            'name' => 'Marca Beta',
            'slug' => 'marca-beta',
            'is_active' => true,
        ]);

        $this->ana = $this->usuarioCon('ana@alfa.test', 'brand_admin', $this->alfa);
        $this->bruno = $this->usuarioCon('bruno@beta.test', 'brand_admin', $this->beta);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@agencia.test',
            'password' => 'secreto-de-prueba',
            'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    private function usuarioCon(string $email, string $rol, Client $cliente): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'secreto-de-prueba',
            'is_active' => true,
        ]);

        $user->assignRole($rol);

        $equipo = Team::create([
            'name' => 'Equipo '.$cliente->name,
            'slug' => 'equipo-'.$cliente->slug,
            'is_active' => true,
        ]);
        $equipo->clients()->attach($cliente->id);
        $user->teams()->attach($equipo->id);

        $user->forgetAccessCache();

        return $user;
    }

    public function test_el_alcance_de_marcas_no_se_cruza(): void
    {
        $this->assertTrue($this->ana->canAccessBrand($this->marcaAlfa->id));
        $this->assertFalse($this->ana->canAccessBrand($this->marcaBeta->id));

        $this->assertTrue($this->bruno->canAccessBrand($this->marcaBeta->id));
        $this->assertFalse($this->bruno->canAccessBrand($this->marcaAlfa->id));
    }

    public function test_no_se_ve_el_cliente_ajeno(): void
    {
        $this->assertTrue($this->ana->can('view', $this->alfa));
        $this->assertFalse($this->ana->can('view', $this->beta));
    }

    public function test_no_se_ve_la_marca_ajena(): void
    {
        $this->assertTrue($this->ana->can('view', $this->marcaAlfa));
        $this->assertFalse($this->ana->can('view', $this->marcaBeta));
    }

    public function test_no_se_ven_las_reglas_del_cliente_ajeno(): void
    {
        $reglasBeta = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Client,
            'owner_id' => $this->beta->id,
            'client_id' => $this->beta->id,
            'version' => 1,
            'name' => 'Lineamientos Beta',
            'status' => RuleSetStatus::Published,
        ]);

        $this->assertFalse($this->ana->can('view', $reglasBeta));
        $this->assertTrue($this->bruno->can('view', $reglasBeta));
    }

    public function test_no_se_ve_la_paleta_de_la_marca_ajena(): void
    {
        $paletaBeta = Palette::create([
            'brand_id' => $this->marcaBeta->id,
            'name' => 'Paleta Beta',
            'default_delta_e_tolerance' => 10,
            'is_active' => true,
        ]);

        $this->assertFalse($this->ana->can('view', $paletaBeta));
        $this->assertTrue($this->bruno->can('view', $paletaBeta));
    }

    /**
     * La escalada de privilegios que el panel permitia antes de las politicas.
     */
    public function test_no_se_pueden_asignar_roles_sin_alcance_global(): void
    {
        $this->assertFalse($this->ana->can('assignRoles', User::class));
        $this->assertTrue($this->admin->can('assignRoles', User::class));
    }

    public function test_no_se_edita_a_un_usuario_de_otro_cliente(): void
    {
        $this->assertFalse($this->ana->can('update', $this->bruno));
        $this->assertTrue($this->ana->can('update', $this->ana));
    }

    public function test_no_se_le_cambia_la_contrasena_a_un_rol_global(): void
    {
        $this->assertFalse($this->ana->can('update', $this->admin));
        $this->assertFalse($this->ana->can('changePassword', $this->admin));
    }

    public function test_un_conjunto_publicado_no_se_edita_ni_se_borra(): void
    {
        $publicado = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Client,
            'owner_id' => $this->alfa->id,
            'client_id' => $this->alfa->id,
            'version' => 1,
            'name' => 'Lineamientos Alfa',
            'status' => RuleSetStatus::Published,
        ]);

        $this->assertFalse($this->ana->can('update', $publicado));
        $this->assertFalse($this->ana->can('delete', $publicado));

        // Ni siquiera un super_admin: la inmutabilidad de lo publicado es la
        // base de la trazabilidad, no un permiso.
        $this->assertFalse($this->admin->can('update', $publicado));
        $this->assertFalse($this->admin->can('delete', $publicado));
    }

    public function test_un_borrador_si_se_edita_dentro_del_alcance(): void
    {
        $borrador = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Client,
            'owner_id' => $this->alfa->id,
            'client_id' => $this->alfa->id,
            'version' => 2,
            'name' => 'Lineamientos Alfa v2',
            'status' => RuleSetStatus::Draft,
        ]);

        $this->assertTrue($this->ana->can('update', $borrador));
        $this->assertFalse($this->bruno->can('update', $borrador));
    }

    /**
     * brand_admin puede publicar reglas de su marca, pero no las corporativas:
     * un cambio de nivel cliente se propaga a todas las marcas del cliente.
     */
    public function test_publicar_a_nivel_cliente_exige_permiso_aparte(): void
    {
        $corporativo = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Client,
            'owner_id' => $this->alfa->id,
            'client_id' => $this->alfa->id,
            'version' => 3,
            'name' => 'Corporativo Alfa',
            'status' => RuleSetStatus::Draft,
        ]);

        $deMarca = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand,
            'owner_id' => $this->marcaAlfa->id,
            'client_id' => $this->alfa->id,
            'version' => 1,
            'name' => 'Marca Alfa v1',
            'status' => RuleSetStatus::Draft,
        ]);

        $this->assertFalse($this->ana->can('publish', $corporativo));
        $this->assertTrue($this->ana->can('publish', $deMarca));
    }

    public function test_el_super_admin_si_atraviesa_los_clientes(): void
    {
        $this->assertTrue($this->admin->can('view', $this->alfa));
        $this->assertTrue($this->admin->can('view', $this->beta));
        $this->assertTrue($this->admin->canAccessBrand($this->marcaBeta->id));
    }

    /**
     * El auditor ve todo y no toca nada. Es el unico rol global sin escritura,
     * y conviene que un test lo fije: es facil de romper agregando un permiso.
     */
    public function test_el_auditor_ve_todo_pero_no_dispara_validaciones(): void
    {
        $auditor = User::create([
            'name' => 'Auditor',
            'email' => 'auditor@agencia.test',
            'password' => 'secreto-de-prueba',
            'is_active' => true,
        ]);
        $auditor->assignRole('auditor');

        // Ve todo.
        $this->assertTrue($auditor->can('view', $this->alfa));
        $this->assertTrue($auditor->can('view', $this->beta));
        $this->assertTrue($auditor->canAccessBrand($this->marcaBeta->id));

        // Y no escribe nada.
        $this->assertFalse($auditor->hasPermissionTo('validation.trigger'));
        $this->assertFalse($auditor->can('assignRoles', User::class));
        $this->assertFalse($auditor->can('create', Team::class));
        $this->assertFalse($auditor->can('create', Client::class));
        $this->assertFalse($auditor->can('update', $this->alfa));
        $this->assertFalse($auditor->can('changePassword', $this->ana));
    }

    /**
     * Fija la distincion que un test anterior destapo: alcance global no es
     * privilegio global. Es facil de volver a romper usando esGlobal() donde
     * corresponde esAdminGlobal().
     */
    public function test_alcance_global_no_equivale_a_privilegio_global(): void
    {
        $auditor = User::create([
            'name' => 'Auditor 2',
            'email' => 'auditor2@agencia.test',
            'password' => 'secreto-de-prueba',
            'is_active' => true,
        ]);
        $auditor->assignRole('auditor');

        // Los dos tienen alcance global...
        $this->assertTrue($auditor->hasGlobalAccess());
        $this->assertTrue($this->admin->hasGlobalAccess());

        // ...y solo uno tiene privilegio de escritura.
        $this->assertFalse($auditor->can('create', Client::class));
        $this->assertTrue($this->admin->can('create', Client::class));
    }
}
