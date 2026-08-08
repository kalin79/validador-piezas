<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OverrideAction;
use App\Enums\RuleCategory;
use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Enums\RuleType;
use App\Enums\Severity;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Palette;
use App\Models\Rule;
use App\Models\RuleSet;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::create([
            'name' => 'Gloria',
            'slug' => 'gloria',
            'legal_name' => 'Gloria S.A.',
            'is_active' => true,
            'settings' => [
                'jurisdiction' => 'PE',
                'contrast_threshold' => 4.5,
            ],
        ]);

        $brands = collect(['Gloria' => 'gloria', 'Pro' => 'pro', 'Bonle' => 'bonle'])
            ->map(fn(string $slug, string $name): Brand => Brand::create([
                'client_id' => $client->id,
                'name' => $name,
                'slug' => $slug,
                'is_active' => true,
            ]));

        // ------------------------------------------------------------------
        // Conjunto corporativo del cliente: lo heredan las tres marcas.
        // ------------------------------------------------------------------
        $corporate = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Client,
            'owner_id' => $client->id,
            'client_id' => $client->id,
            'version' => 1,
            'name' => 'Lineamientos corporativos Gloria v1',
            'changelog' => 'Version inicial.',
            'status' => RuleSetStatus::Published,
            'published_at' => now(),
        ]);

        // is_locked: ninguna marca puede desactivarlas ni relajarlas.
        $corporate->rules()->create([
            'code' => 'COMP-001',
            'category' => RuleCategory::Compliance,
            'type' => RuleType::Judgment,
            'severity' => Severity::Blocking,
            'title' => 'Prohibido prometer o garantizar rendimientos',
            'statement' => 'La pieza no debe afirmar, sugerir ni implicar rendimientos garantizados, '
                . 'ganancias aseguradas o ausencia de riesgo. Las proyecciones deben presentarse '
                . 'explicitamente como estimaciones, nunca como hechos.',
            'is_locked' => true,
            'sort_order' => 1,
        ]);

        $corporate->rules()->create([
            'code' => 'COMP-002',
            'category' => RuleCategory::Compliance,
            'type' => RuleType::Judgment,
            'severity' => Severity::Blocking,
            'title' => 'Leyenda de riesgo obligatoria',
            'statement' => 'Toda pieza que comunique un producto de inversion debe incluir la leyenda '
                . 'de riesgo legible, con contraste suficiente y sin recortes.',
            'is_locked' => true,
            'sort_order' => 2,
        ]);

        // Sin bloquear: la marca Pro la endurece mas abajo.
        $corporate->rules()->create([
            'code' => 'COPY-010',
            'category' => RuleCategory::Copy,
            'type' => RuleType::Judgment,
            'severity' => Severity::Major,
            'title' => 'Lexico prohibido corporativo',
            'statement' => 'No usar los terminos: "gratis total", "sin riesgo", "ganancia segura", '
                . '"la mejor del mercado".',
            'sort_order' => 3,
        ]);

        // ------------------------------------------------------------------
        // Marca Pro: paleta propia y conjunto de reglas propio.
        // ------------------------------------------------------------------
        $pro = $brands['Pro'];

        $palette = Palette::create([
            'brand_id' => $pro->id,
            'name' => 'Paleta Pro 2026',
            'default_delta_e_tolerance' => 5.00,
            'is_active' => true,
        ]);

        $palette->colors()->createMany([
            ['name' => 'Azul Pro', 'hex' => '#0B3D91', 'role' => 'primary', 'delta_e_tolerance' => 2.5, 'sort_order' => 1],
            ['name' => 'Blanco', 'hex' => '#FFFFFF', 'role' => 'background', 'sort_order' => 2],
            ['name' => 'Gris texto', 'hex' => '#333333', 'role' => 'text', 'sort_order' => 3],
            ['name' => 'Rojo competencia', 'hex' => '#E30613', 'role' => 'accent', 'is_forbidden' => true, 'sort_order' => 4],
        ]);

        $proSet = RuleSet::create([
            'owner_type' => RuleSetOwnerType::Brand,
            'owner_id' => $pro->id,
            'client_id' => $client->id,
            'version' => 1,
            'name' => 'Reglas de marca Pro v1',
            'changelog' => 'Version inicial.',
            'status' => RuleSetStatus::Published,
            'published_at' => now(),
        ]);

        $proSet->rules()->create([
            'palette_id' => $palette->id,
            'code' => 'PAL-500',
            'category' => RuleCategory::Palette,
            'type' => RuleType::Deterministic,
            'severity' => Severity::Major,
            'title' => 'Usar solo colores de la paleta Pro',
            'statement' => 'Los colores dominantes de la pieza deben pertenecer a la paleta autorizada, '
                . 'dentro de la tolerancia Delta E definida por color.',
            'sort_order' => 1,
        ]);

        $proSet->rules()->create([
            'code' => 'TONE-500',
            'category' => RuleCategory::Tone,
            'type' => RuleType::Judgment,
            'severity' => Severity::Minor,
            'title' => 'Tono cercano y directo',
            'statement' => 'Tuteo, frases cortas, sin tecnicismos financieros sin explicar.',
            'sort_order' => 2,
        ]);

        // Override: Pro endurece la regla corporativa de lexico.
        //
        // Se usa codigo propio en el rango 900-999 y se declara a quien anula
        // en overrides_code, en vez de repetir el codigo heredado. El resultado
        // en el resolver es el mismo, pero el listado se lee solo: un COPY-900
        // anuncia que anula algo, mientras que un COPY-010 dentro de un conjunto
        // de marca es ambiguo entre override deliberado y numero reutilizado
        // por descuido.
        $proSet->rules()->create([
            'code' => 'COPY-900',
            'category' => RuleCategory::Copy,
            'type' => RuleType::Judgment,
            'severity' => Severity::Blocking,
            'title' => 'Lexico prohibido Pro (mas estricto)',
            'statement' => 'Ademas del lexico corporativo prohibido, Pro no admite superlativos '
                . 'absolutos de ningun tipo.',
            'override_action' => OverrideAction::Replace,
            'overrides_code' => 'COPY-010',
            'sort_order' => 3,
        ]);

        // ------------------------------------------------------------------
        // Equipos: uno con acceso a todo el cliente, otro solo a una marca.
        // ------------------------------------------------------------------
        $cuenta = Team::create([
            'name' => 'Cuenta Gloria',
            'slug' => 'cuenta-gloria',
            'description' => 'Equipo interno que atiende todas las marcas de Gloria.',
            'is_external' => false,
            'is_active' => true,
        ]);
        $cuenta->clients()->attach($client->id);

        $agencia = Team::create([
            'name' => 'Agencia externa Pro',
            'slug' => 'agencia-pro',
            'description' => 'Proveedor que solo produce piezas de la marca Pro.',
            'is_external' => true,
            'is_active' => true,
        ]);
        $agencia->brands()->attach($pro->id);

        // ------------------------------------------------------------------
        // Usuarios de prueba.
        // ------------------------------------------------------------------
        $admin = User::firstOrCreate(
            ['email' => 'c.augusto.espinoza@gmail.com'],
            ['name' => 'Administrador', 'password' => '123456', 'is_active' => true],
        );
        $admin->assignRole('super_admin');
        $admin->forceFill(['active_brand_id' => $pro->id])->save();

        $disenador = User::firstOrCreate(
            ['email' => 'agencia@validador.test'],
            ['name' => 'Disenador Agencia', 'password' => 'password', 'is_active' => true],
        );
        $disenador->assignRole('uploader');
        $disenador->teams()->syncWithoutDetaching([$agencia->id]);
        $disenador->forceFill(['active_brand_id' => $pro->id])->save();

        $revisor = User::firstOrCreate(
            ['email' => 'revisor@validador.test'],
            ['name' => 'Revisor Gloria', 'password' => 'password', 'is_active' => true],
        );
        $revisor->assignRole('reviewer');
        $revisor->teams()->syncWithoutDetaching([$cuenta->id]);
        $revisor->forceFill(['active_brand_id' => $brands['Gloria']->id])->save();

        $this->command?->info(sprintf(
            'Demo: %d cliente, %d marcas, %d equipos, %d usuarios, %d reglas.',
            Client::count(),
            Brand::count(),
            Team::count(),
            User::count(),
            Rule::count(),
        ));
        $this->command?->info('Claves: admin@ / agencia@ / revisor@validador.test — password');
        $this->command?->info('Convencion de codigos: 001-499 cliente, 500-899 marca, 900-999 override.');
    }
}
