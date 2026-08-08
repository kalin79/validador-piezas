<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Rule;
use App\Models\RuleSet;
use App\Services\RuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Brand $brand;

    private RuleSet $corporativo;

    private RuleSet $deMarca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create(['name' => 'Gloria', 'slug' => 'gloria']);

        $this->brand = Brand::create([
            'client_id' => $this->client->id,
            'name' => 'Pro',
            'slug' => 'pro',
        ]);

        $this->corporativo = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Client,
            'owner_id' => $this->client->id,
            'client_id' => $this->client->id,
            'version' => 1,
            'name' => 'Corporativo v1',
            'status' => RuleSetStatus::Published,
        ]);

        $this->deMarca = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand,
            'owner_id' => $this->brand->id,
            'client_id' => $this->client->id,
            'version' => 1,
            'name' => 'Pro v1',
            'status' => RuleSetStatus::Published,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function regla(RuleSet $conjunto, string $code, array $extra = []): Rule
    {
        return $conjunto->rules()->create(array_merge([
            'code' => $code,
            'category' => 'compliance',
            'type' => 'judgment',
            'severity' => 'major',
            'title' => "Regla {$code}",
            'statement' => 'Enunciado de prueba.',
            'is_active' => true,
        ], $extra));
    }

    private function resolver(?string $canal = null): \App\Services\ResolvedRuleSet
    {
        return app(RuleResolver::class)->resolve($this->brand->fresh(), $canal);
    }

    public function test_hereda_las_reglas_del_cliente_cuando_la_marca_no_tiene_propias(): void
    {
        $this->regla($this->corporativo, 'COMP-001');
        $this->regla($this->corporativo, 'COMP-002');

        $resuelto = $this->resolver();

        $this->assertCount(2, $resuelto->rules);
        $this->assertSame(['client'], array_values(array_unique(array_column($resuelto->snapshot, 'origin'))));
    }

    public function test_suma_las_reglas_propias_a_las_heredadas(): void
    {
        $this->regla($this->corporativo, 'COMP-001');
        $this->regla($this->deMarca, 'TONE-500');

        $this->assertCount(2, $this->resolver()->rules);
    }

    public function test_la_marca_puede_reemplazar_una_regla_heredada_con_el_mismo_codigo(): void
    {
        $this->regla($this->corporativo, 'COPY-010', ['severity' => 'minor']);
        $this->regla($this->deMarca, 'COPY-010', ['severity' => 'blocking']);

        $resuelto = $this->resolver();

        $this->assertCount(1, $resuelto->rules, 'Debe reemplazar, no sumar');
        $this->assertSame('brand_override', $resuelto->snapshot[0]['origin']);
        $this->assertSame('blocking', $resuelto->snapshot[0]['severity']);
    }

    public function test_la_marca_puede_desactivar_una_regla_heredada_no_bloqueada(): void
    {
        $this->regla($this->corporativo, 'PAL-001');
        $this->regla($this->deMarca, 'PAL-900', [
            'override_action' => 'disable',
            'overrides_code' => 'PAL-001',
        ]);

        $this->assertCount(0, $this->resolver()->rules);
    }

    public function test_una_regla_corporativa_bloqueada_no_se_puede_desactivar(): void
    {
        $this->regla($this->corporativo, 'COMP-001', ['is_locked' => true, 'severity' => 'blocking']);
        $this->regla($this->deMarca, 'COMP-900', [
            'override_action' => 'disable',
            'overrides_code' => 'COMP-001',
        ]);

        $resuelto = $this->resolver();

        $this->assertCount(1, $resuelto->rules);
        $this->assertSame('COMP-001', $resuelto->snapshot[0]['code']);
        $this->assertSame('client', $resuelto->snapshot[0]['origin']);
    }

    public function test_una_regla_corporativa_bloqueada_no_se_puede_relajar(): void
    {
        $this->regla($this->corporativo, 'COMP-001', ['is_locked' => true, 'severity' => 'blocking']);
        $this->regla($this->deMarca, 'COMP-001', ['severity' => 'info']);

        $this->assertSame('blocking', $this->resolver()->snapshot[0]['severity']);
    }

    public function test_excluye_reglas_que_no_aplican_al_canal(): void
    {
        $this->regla($this->deMarca, 'FMT-500', ['applies_to_channels' => ['instagram_story']]);

        $this->assertCount(0, $this->resolver('instagram_post')->rules);
        $this->assertCount(1, $this->resolver('instagram_story')->rules);
    }

    public function test_produce_un_hash_estable_para_el_mismo_conjunto_efectivo(): void
    {
        $this->regla($this->corporativo, 'COMP-001');
        $this->regla($this->deMarca, 'TONE-500');

        $this->assertSame($this->resolver()->hash, $this->resolver()->hash);
    }

    public function test_el_hash_cambia_cuando_cambian_las_reglas_efectivas(): void
    {
        $this->regla($this->corporativo, 'COMP-001');
        $antes = $this->resolver()->hash;

        $this->regla($this->deMarca, 'TONE-500');

        $this->assertNotSame($antes, $this->resolver()->hash);
    }

    public function test_ignora_conjuntos_en_borrador(): void
    {
        $this->deMarca->update(['status' => RuleSetStatus::Draft]);
        $this->regla($this->corporativo, 'COMP-001');
        $this->regla($this->deMarca, 'TONE-500');

        $this->assertCount(1, $this->resolver()->rules);
    }

    public function test_ignora_reglas_inactivas(): void
    {
        $this->regla($this->corporativo, 'COMP-001');
        $this->regla($this->deMarca, 'TONE-500', ['is_active' => false]);

        $this->assertCount(1, $this->resolver()->rules);
    }
}
