<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            // De donde entro la carga. En auditoria importa: una validacion
            // disparada desde un plugin mientras el disenador iteraba no tiene
            // el mismo peso que una carga formal hecha por el revisor.
            $table->string('source', 20)->default('panel')->after('channel');

            // Identificador del origen: el archivo o nodo de Figma. Agrupa las
            // iteraciones de una misma pieza en una sola carga.
            $table->string('external_ref', 191)->nullable()->after('source');

            $table->index('source');
            $table->index(['brand_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'external_ref']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'external_ref']);
        });
    }
};
