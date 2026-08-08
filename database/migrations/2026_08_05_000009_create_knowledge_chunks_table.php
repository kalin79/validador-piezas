<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_set_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_rule_id')->nullable()->constrained('rules')->nullOnDelete();
            $table->string('source_type', 40);
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->string('embedding_model', 100)->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'rule_set_id']);
            $table->index('embedding_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
