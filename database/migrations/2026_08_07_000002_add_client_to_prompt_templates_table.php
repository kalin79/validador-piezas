<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table): void {
            // El alcance pasa a tener tres niveles, igual que los conjuntos de
            // reglas: general, por cliente y por marca. Antes solo existian
            // general y por marca, y no habia forma de escribir una instruccion
            // que valiera para todas las marcas de un cliente.
            $table->foreignId('client_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();

            $table->index(['key', 'client_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table): void {
            $table->dropIndex(['key', 'client_id', 'brand_id']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
