<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RuleCategory;
use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Enums\RuleType;
use App\Enums\Severity;
use App\Models\Brand;
use App\Models\Palette;
use App\Models\RuleSet;
use Illuminate\Database\Seeder;

/**
 * Agrega a cada marca las reglas deterministas que el motor de la Fase 2 sabe
 * evaluar. Se crean sobre una version nueva del conjunto, no editando la
 * publicada: es el mismo flujo que seguiria un usuario desde el panel.
 */
class DeterministicRulesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Brand::query()->with('client')->get() as $brand) {
            $publicado = RuleSet::query()
                ->where('owner_type', RuleSetOwnerType::Brand->value)
                ->where('owner_id', $brand->id)
                ->where('status', RuleSetStatus::Published->value)
                ->orderByDesc('version')
                ->with('rules')
                ->first();

            // Si ya tiene reglas deterministas, no se duplican.
            if ($publicado?->rules->contains(fn ($r) => $r->type === RuleType::Deterministic)) {
                $this->command?->info("· {$brand->name}: ya tiene reglas deterministas, se omite.");

                continue;
            }

            $version = (int) RuleSet::query()
                ->where('owner_type', RuleSetOwnerType::Brand->value)
                ->where('owner_id', $brand->id)
                ->max('version') + 1;

            $nuevo = RuleSet::create([
                'owner_type' => RuleSetOwnerType::Brand,
                'owner_id' => $brand->id,
                'client_id' => $brand->client_id,
                'version' => $version,
                'name' => "Reglas de marca {$brand->name} v{$version}",
                'changelog' => 'Se agregan las reglas deterministas de formato, paleta y contraste.',
                'status' => RuleSetStatus::Published,
                'published_at' => now(),
            ]);

            // Se arrastran las reglas de la version anterior para no perderlas.
            foreach ($publicado?->rules ?? [] as $anterior) {
                $copia = $anterior->replicate();
                $copia->rule_set_id = $nuevo->id;
                $copia->save();
            }

            $paleta = Palette::query()
                ->where('brand_id', $brand->id)
                ->where('is_active', true)
                ->first();

            if ($paleta !== null) {
                $nuevo->rules()->create([
                    'palette_id' => $paleta->id,
                    'code' => 'PAL-501',
                    'category' => RuleCategory::Palette,
                    'type' => RuleType::Deterministic,
                    'severity' => Severity::Major,
                    'title' => 'Colores dentro de la paleta autorizada',
                    'statement' => 'Los colores con presencia significativa en la pieza deben pertenecer a la '
                        .'paleta autorizada, dentro de la tolerancia Delta E definida para cada color. '
                        .'Los colores marcados como prohibidos no pueden aparecer.',
                    'sort_order' => 10,
                ]);
            }

            $nuevo->rules()->create([
                'code' => 'FMT-501',
                'category' => RuleCategory::Composition,
                'type' => RuleType::Deterministic,
                'severity' => Severity::Major,
                'title' => 'Formato correcto para el canal',
                'statement' => 'Las dimensiones, la relacion de aspecto y el peso del archivo deben corresponder '
                    .'a la especificacion del canal de publicacion declarado.',
                'sort_order' => 20,
            ]);

            $nuevo->rules()->create([
                'code' => 'TYPO-501',
                'category' => RuleCategory::Typography,
                'type' => RuleType::Deterministic,
                'severity' => Severity::Info,
                'title' => 'Contraste suficiente entre colores dominantes',
                'statement' => 'El contraste entre los colores dominantes debe alcanzar el umbral configurado '
                    .'para la marca. Aproximacion: no reemplaza la medicion sobre las zonas de texto reales.',
                'sort_order' => 30,
            ]);

            $this->command?->info("· {$brand->name}: conjunto v{$version} publicado con reglas deterministas.");
        }
    }
}
