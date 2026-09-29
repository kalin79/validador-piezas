<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedor de IA de cada ejecucion (anthropic, openai, fake).
 *
 * Se guarda aparte del modelo para que el reporte de consumo agrupe por
 * proveedor con un indice, sin depender de prefijos en cada consulta.
 * Las ejecuciones existentes se completan desde su modelo: hasta hoy solo
 * habia Claude, y las simuladas quedan como 'fake'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_runs', function (Blueprint $table): void {
            $table->string('ai_provider', 20)->nullable()->after('model_identifier');
            $table->index(['ai_provider', 'created_at']);
        });

        DB::table('validation_runs')
            ->where('model_identifier', 'like', 'claude-%')
            ->update(['ai_provider' => 'anthropic']);

        DB::table('validation_runs')
            ->where('model_identifier', 'like', 'gpt-%')
            ->update(['ai_provider' => 'openai']);

        DB::table('validation_runs')
            ->where('deterministic_results->ai_simulated', true)
            ->update(['ai_provider' => 'fake']);
    }

    public function down(): void
    {
        Schema::table('validation_runs', function (Blueprint $table): void {
            $table->dropIndex(['ai_provider', 'created_at']);
            $table->dropColumn('ai_provider');
        });
    }
};
