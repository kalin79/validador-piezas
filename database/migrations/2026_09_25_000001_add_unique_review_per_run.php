<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una ejecucion se revisa una sola vez, garantizado por la base.
 *
 * ReviewRecorder ya lo comprueba con la fila bloqueada; el indice cubre
 * cualquier otra via. Si hoy existen duplicados la migracion se detiene y los
 * lista: decidir cual revision vale es una decision humana, no de un script.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicados = DB::table('human_reviews')
            ->select('validation_run_id', DB::raw('count(*) as n'))
            ->groupBy('validation_run_id')
            ->having('n', '>', 1)
            ->pluck('n', 'validation_run_id');

        if ($duplicados->isNotEmpty()) {
            throw new RuntimeException(
                'Hay ejecuciones con mas de una revision humana (validation_run_id => cantidad): '
                .json_encode($duplicados->all())
                .'. Resuelvelas antes de migrar.'
            );
        }

        Schema::table('human_reviews', function (Blueprint $table): void {
            $table->unique('validation_run_id', 'human_reviews_una_por_ejecucion');
        });
    }

    public function down(): void
    {
        Schema::table('human_reviews', function (Blueprint $table): void {
            $table->dropUnique('human_reviews_una_por_ejecucion');
        });
    }
};
