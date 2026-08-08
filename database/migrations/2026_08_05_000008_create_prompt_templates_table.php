<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('key', 60);
            $table->unsignedInteger('version');
            $table->string('name');
            $table->longText('system_prompt');
            $table->longText('user_prompt_template');
            $table->json('output_schema')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['key', 'status']);
            $table->index(['brand_id', 'key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_templates');
    }
};
