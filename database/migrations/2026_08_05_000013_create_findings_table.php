<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('validation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_code', 50)->nullable();
            $table->string('category', 40);
            $table->string('severity', 20);
            $table->string('origin', 20);
            $table->text('description');
            $table->text('evidence')->nullable();
            $table->json('evidence_data')->nullable();
            $table->text('suggestion')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('review_state', 30)->default('unreviewed');
            $table->timestamps();

            $table->index(['validation_run_id', 'severity']);
            $table->index(['category', 'review_state']);
            $table->index('rule_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
