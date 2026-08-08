<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('palettes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('default_delta_e_tolerance', 5, 2)->default(5.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'is_active']);
        });

        Schema::create('palette_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('palette_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('hex', 7);
            $table->json('lab')->nullable();
            $table->string('role', 30)->default('secondary');
            $table->decimal('delta_e_tolerance', 5, 2)->nullable();
            $table->boolean('is_forbidden')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['palette_id', 'is_forbidden']);
            $table->index('hex');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palette_colors');
        Schema::dropIfExists('palettes');
    }
};
