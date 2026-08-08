<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('validation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('machine_verdict', 40);
            $table->string('final_verdict', 40);
            $table->boolean('overrode_machine')->default(false);
            $table->text('justification')->nullable();
            $table->json('finding_decisions')->nullable();
            $table->timestamps();

            $table->index(['validation_run_id', 'created_at']);
            $table->index('reviewer_id');
            $table->index('overrode_machine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('human_reviews');
    }
};
