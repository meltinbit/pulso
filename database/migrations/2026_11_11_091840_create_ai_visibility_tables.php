<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_properties', function (Blueprint $table) {
            $table->json('ai_brand_terms')->nullable()->after('bing_site_url');
        });

        Schema::create('ai_visibility_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained()->cascadeOnDelete();
            $table->string('prompt', 500);
            $table->boolean('is_active')->default(true);
            $table->string('agent', 50)->nullable();
            $table->timestamps();

            $table->unique(['ga_property_id', 'prompt'], 'ai_prompts_property_prompt_unique');
        });

        Schema::create('ai_visibility_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_visibility_prompt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ga_property_id')->constrained()->cascadeOnDelete();
            $table->date('run_date');
            $table->string('engine', 20);
            $table->string('model', 50);
            $table->boolean('mentioned');
            $table->unsignedTinyInteger('position')->nullable();
            $table->string('cited_url', 2048)->nullable();
            $table->json('cited_domains')->nullable();
            $table->text('answer_excerpt')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedSmallInteger('searches')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['ga_property_id', 'run_date'], 'ai_checks_property_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_checks');
        Schema::dropIfExists('ai_visibility_prompts');

        Schema::table('ga_properties', function (Blueprint $table) {
            $table->dropColumn('ai_brand_terms');
        });
    }
};
