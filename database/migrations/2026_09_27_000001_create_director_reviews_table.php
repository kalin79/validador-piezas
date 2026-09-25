<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envios al director.
 *
 * Cada fila es un envio de UNA pieza (asset) apoyado en UNA validacion
 * concreta: queda fijado con que evidencia se envio, aunque despues se
 * revalide. La decision (aprobar o devolver) se escribe una sola vez.
 *
 * pending_asset_id es una columna generada que solo tiene valor mientras el
 * envio esta pendiente. Su indice unico garantiza en la base, y no solo en el
 * codigo, que una pieza no tenga dos envios pendientes a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('director_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('validation_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();

            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->text('sender_note')->nullable();
            // Copia del veredicto y el puntaje al momento de enviar. El director
            // decide sobre esto; si luego se revalida, la diferencia se ve.
            $table->string('verdict_at_send', 40);
            $table->boolean('verdict_from_human')->default(false);
            $table->decimal('score_at_send', 5, 2)->nullable();

            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('pending_asset_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'pending' THEN asset_id END");
            $table->unique('pending_asset_id', 'director_reviews_un_pendiente_por_pieza');

            $table->index(['brand_id', 'status', 'created_at']);
            $table->index(['asset_id', 'created_at']);
            $table->index('sent_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('director_reviews');
    }
};
