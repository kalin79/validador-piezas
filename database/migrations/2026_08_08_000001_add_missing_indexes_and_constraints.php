<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * 1. Una sola version por clave y alcance en prompt_templates.
         *
         * rule_sets ya tiene unique(owner_type, owner_id, version). Las
         * plantillas no lo tenian, y siguienteVersion() hace max()+1 sin
         * bloqueo: dos publicaciones concurrentes producen dos v5 del mismo
         * alcance, y resolveFor() elige una arbitrariamente. El sintoma seria
         * "el sistema evaluo con instrucciones distintas y nadie sabe por que".
         *
         * client_id y brand_id son nulos en las plantillas generales. MySQL
         * permite duplicados cuando hay nulos en un indice unico, asi que este
         * indice no protege ese caso; para el resto si.
         */
        if (! $this->indiceExiste('prompt_templates', 'prompt_templates_alcance_version_unique')) {
            $duplicados = DB::table('prompt_templates')
                ->select('key', 'client_id', 'brand_id', 'version', DB::raw('COUNT(*) as n'))
                ->groupBy('key', 'client_id', 'brand_id', 'version')
                ->having('n', '>', 1)
                ->get();

            if ($duplicados->isNotEmpty()) {
                // No se toca nada: corregir versiones a ciegas podria dejar
                // ejecuciones historicas apuntando a la plantilla equivocada.
                throw new RuntimeException(
                    'Hay '.$duplicados->count().' combinaciones de clave+alcance+version repetidas en '
                    .'prompt_templates. Resuelvelas a mano antes de migrar: '
                    .$duplicados->map(fn ($d): string => "{$d->key}/c{$d->client_id}/b{$d->brand_id}/v{$d->version}")->implode(', ')
                );
            }

            Schema::table('prompt_templates', function (Blueprint $table): void {
                $table->unique(
                    ['key', 'client_id', 'brand_id', 'version'],
                    'prompt_templates_alcance_version_unique'
                );
            });
        }

        /*
         * 2. Indices que le faltan a findings.
         *
         * Las dos consultas de CalidadDelMotor filtran por created_at y por
         * review_state sobre el join completo. Con pocos hallazgos no se nota;
         * con cincuenta mil, la pagina tarda segundos y el cliente concluye que
         * el sistema es lento.
         */
        Schema::table('findings', function (Blueprint $table): void {
            if (! $this->indiceExiste('findings', 'findings_created_at_index')) {
                $table->index('created_at');
            }

            if (! $this->indiceExiste('findings', 'findings_review_state_index')) {
                $table->index('review_state');
            }
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->dropIndex('findings_created_at_index');
            $table->dropIndex('findings_review_state_index');
        });

        Schema::table('prompt_templates', function (Blueprint $table): void {
            $table->dropUnique('prompt_templates_alcance_version_unique');
        });
    }

    /**
     * Se comprueba antes de crear para que la migracion sea reejecutable en
     * instalaciones donde alguno de los indices ya se agrego a mano.
     *
     * Se usa Schema::hasIndex y no "SHOW INDEX FROM": esa sentencia es de
     * MySQL y la suite de pruebas corre sobre SQLite, asi que una migracion
     * escrita con sintaxis especifica del motor rompe todos los tests que
     * usan RefreshDatabase. El constructor de esquemas de Laravel traduce a
     * cada motor.
     */
    private function indiceExiste(string $tabla, string $indice): bool
    {
        return Schema::hasIndex($tabla, $indice);
    }
};
