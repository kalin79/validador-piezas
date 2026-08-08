<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('original_filename');
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path');
            $table->char('file_hash', 64);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('extracted_metadata')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('extracted_palette')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'file_hash']);
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
