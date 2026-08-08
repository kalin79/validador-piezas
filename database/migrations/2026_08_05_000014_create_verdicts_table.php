<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('validation_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 40);
            $table->decimal('score', 5, 2)->nullable();
            $table->json('category_breakdown')->nullable();
            $table->unsignedSmallInteger('blocking_count')->default(0);
            $table->unsignedSmallInteger('major_count')->default(0);
            $table->unsignedSmallInteger('minor_count')->default(0);
            $table->json('scoring_formula_snapshot')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdicts');
    }
};
