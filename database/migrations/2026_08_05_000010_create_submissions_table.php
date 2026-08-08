<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('campaign')->nullable();
            $table->string('product')->nullable();
            $table->string('channel', 60)->nullable();
            $table->text('objective')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
