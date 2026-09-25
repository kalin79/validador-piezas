<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\RuleSets\Pages\ListRuleSets;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Brand;
use App\Models\Client;
use App\Models\PromptTemplate;
use App\Models\RuleSet;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Autorizacion en el panel, a traves de los componentes reales de Filament.
 *
 * AislamientoEntreClientesTest prueba las politicas. Esto prueba lo que la
 * auditoria encontro abierto: formularios que aceptaban ids de otro cliente y
 * acciones que no pasaban por ninguna politica.
 */
class AutorizacionPanelTest extends TestCase
{
    use RefreshDatabase;

    private Client $alfa;

    private Client $beta;

    private Brand $marcaAlfa;

    private Brand $marcaBeta;

    private Team $equipoAlfa;

    private Team $equipoBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->alfa = Client::create(['name' => 'Alfa', 'slug' => 'alfa', 'is_active' => true]);
        $this->beta = Client::create(['name' => 'Beta', 'slug' => 'beta', 'is_active' => true]);
        $this->marcaAlfa = Brand::create(['client_id' => $this->alfa->id, 'name' => 'MA', 'slug' => 'ma', 'is_active' => true]);
        $this->marcaBeta = Brand::create(['client_id' => $this->beta->id, 'name' => 'MB', 'slug' => 'mb', 'is_active' => true]);

        $this->equipoAlfa = Team::create(['name' => 'Equipo Alfa', 'slug' => 'equipo-alfa', 'is_active' => true]);
        $this->equipoAlfa->clients()->attach($this->alfa->id);

        $this->equipoBeta = Team::create(['name' => 'Equipo Beta', 'slug' => 'equipo-beta', 'is_active' => true]);
        $this->equipoBeta->clients()->attach($this->beta->id);
    }

    private function usuario(string $email, string $rol, ?Team $equipo): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secreto-de-prueba', 'is_active' => true]);
        $u->assignRole($rol);

        if ($equipo !== null) {
            $u->teams()->attach($equipo->id);
        }

        $u->forgetAccessCache();

        return $u;
    }

    public function test_client_admin_no_puede_darse_acceso_a_otro_cliente_desde_su_equipo(): void
    {
        $admin = $this->usuario('ca@alfa.test', 'client_admin', $this->equipoAlfa);
        $this->actingAs($admin);

        Livewire::test(EditTeam::class, ['record' => $this->equipoAlfa->getRouteKey()])
            ->fillForm(['clients' => [$this->alfa->id, $this->beta->id]])
            ->call('save')
            ->assertHasFormErrors(['clients']);

        $this->assertFalse($this->equipoAlfa->clients()->whereKey($this->beta->id)->exists());
    }

    public function test_client_admin_si_puede_editar_su_equipo_dentro_de_su_alcance(): void
    {
        $admin = $this->usuario('ca@alfa.test', 'client_admin', $this->equipoAlfa);
        $this->actingAs($admin);

        Livewire::test(EditTeam::class, ['record' => $this->equipoAlfa->getRouteKey()])
            ->fillForm(['clients' => [$this->alfa->id], 'brands' => [$this->marcaAlfa->id], 'name' => 'Equipo Alfa 2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Equipo Alfa 2', $this->equipoAlfa->fresh()->name);
        $this->assertTrue($this->equipoAlfa->brands()->whereKey($this->marcaAlfa->id)->exists());
    }

    public function test_client_admin_no_puede_agregarse_al_equipo_de_otro_cliente(): void
    {
        $admin = $this->usuario('ca@alfa.test', 'client_admin', $this->equipoAlfa);
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['teams' => [$this->equipoAlfa->id, $this->equipoBeta->id]])
            ->call('save')
            ->assertHasFormErrors(['teams']);

        $this->assertFalse($admin->teams()->whereKey($this->equipoBeta->id)->exists());
    }

    public function test_client_admin_no_puede_mudar_una_marca_a_otro_cliente(): void
    {
        $admin = $this->usuario('ca@alfa.test', 'client_admin', $this->equipoAlfa);
        $this->actingAs($admin);

        Livewire::test(EditBrand::class, ['record' => $this->marcaAlfa->getRouteKey()])
            ->fillForm(['client_id' => $this->beta->id])
            ->call('save');

        $this->assertSame($this->alfa->id, $this->marcaAlfa->fresh()->client_id);
    }

    public function test_quien_solo_ve_reglas_no_puede_publicarlas(): void
    {
        $uploader = $this->usuario('up@alfa.test', 'uploader', $this->equipoAlfa);

        $borrador = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand,
            'owner_id' => $this->marcaAlfa->id,
            'client_id' => $this->alfa->id,
            'version' => 1,
            'name' => 'Borrador',
            'status' => RuleSetStatus::Draft,
        ]);
        $borrador->rules()->create([
            'code' => 'COMP-001', 'category' => 'compliance', 'type' => 'judgment', 'severity' => 'major',
            'title' => 'x', 'statement' => 'x', 'is_active' => true,
        ]);

        $this->actingAs($uploader);

        Livewire::test(ListRuleSets::class)
            ->assertTableActionHidden('publish', $borrador);

        try {
            Livewire::test(ListRuleSets::class)->callTableAction('publish', $borrador);
        } catch (\Throwable) {
            // Filament puede rechazar la llamada con excepcion o ignorarla.
        }

        $this->assertSame(RuleSetStatus::Draft, $borrador->fresh()->status);
    }

    public function test_brand_admin_no_publica_instrucciones_para_todo_el_cliente(): void
    {
        $equipoMarca = Team::create(['name' => 'Solo MA', 'slug' => 'solo-ma', 'is_active' => true]);
        $equipoMarca->brands()->attach($this->marcaAlfa->id);
        $ba = $this->usuario('ba@alfa.test', 'brand_admin', $equipoMarca);

        $plantilla = new PromptTemplate([
            'key' => 'piece_validation',
            'client_id' => $this->alfa->id,
            'brand_id' => null,
            'version' => 1,
            'name' => 'Nivel cliente',
            'system_prompt' => 'x',
            'user_prompt_template' => 'x',
            'status' => RuleSetStatus::Draft->value,
        ]);

        $this->assertFalse($ba->can('publish', $plantilla));
        $this->assertFalse($ba->can('update', $plantilla));
    }

    public function test_brand_admin_con_una_marca_no_edita_reglas_corporativas(): void
    {
        $equipoMarca = Team::create(['name' => 'Solo MA', 'slug' => 'solo-ma', 'is_active' => true]);
        $equipoMarca->brands()->attach($this->marcaAlfa->id);
        $ba = $this->usuario('ba@alfa.test', 'brand_admin', $equipoMarca);

        $corporativo = new RuleSet([
            'owner_type' => RuleSetOwnerType::Client->value,
            'owner_id' => $this->alfa->id,
            'client_id' => $this->alfa->id,
            'status' => RuleSetStatus::Draft->value,
        ]);

        $this->assertFalse($ba->can('update', $corporativo));
    }
}
