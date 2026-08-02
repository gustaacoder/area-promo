<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('gemini');
            $table->string('model')->default('gemini-3.5-flash-lite');
            $table->longText('system_prompt');
            $table->longText('user_prompt_template');
            $table->decimal('temperature', 3, 2)->default(1.00);
            $table->decimal('top_p', 3, 2)->default(0.95);
            $table->unsignedInteger('max_output_tokens')->default(350);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
