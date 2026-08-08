<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('palette_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50);
            $table->string('category', 40);
            $table->string('type', 20);
            $table->string('severity', 20);
            $table->string('title');
            $table->text('statement');
            $table->json('parameters')->nullable();
            $table->json('positive_examples')->nullable();
            $table->json('negative_examples')->nullable();
            $table->json('applies_to_channels')->nullable();
            $table->string('override_action', 20)->nullable();
            $table->string('overrides_code', 50)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['rule_set_id', 'code']);
            $table->index(['rule_set_id', 'category']);
            $table->index(['rule_set_id', 'is_active']);
            $table->index('overrides_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
