<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('name');
            $table->text('description')->nullable();

            // Archivo de referencia.
            $table->string('storage_disk', 40)->default('public');
            $table->string('storage_path');
            $table->char('file_hash', 64);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Reglas de uso.
            $table->boolean('is_required')->default(false);
            $table->decimal('min_width_percent', 5, 2)->nullable();
            $table->decimal('clear_space_ratio', 5, 2)->nullable();
            $table->json('allowed_positions')->nullable();
            $table->json('applies_to_channels')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'is_active']);
            $table->index(['brand_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_assets');
    }
};
