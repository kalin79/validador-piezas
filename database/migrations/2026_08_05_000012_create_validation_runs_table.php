<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();

            // Trazabilidad: los conjuntos usados no se pueden borrar.
            $table->foreignId('client_rule_set_id')->nullable()->constrained('rule_sets')->restrictOnDelete();
            $table->foreignId('brand_rule_set_id')->nullable()->constrained('rule_sets')->restrictOnDelete();
            $table->foreignId('prompt_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('resolved_rules_snapshot')->nullable();
            $table->char('resolution_hash', 64)->nullable();

            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('model_identifier', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->json('deterministic_results')->nullable();
            $table->longText('raw_model_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'created_at']);
            $table->index(['asset_id', 'created_at']);
            $table->index('status');
            $table->index('resolution_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_runs');
    }
};
