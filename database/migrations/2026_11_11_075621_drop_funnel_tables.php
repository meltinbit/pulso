<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Funnels are now run ad hoc through the get-property-funnel MCP tool,
     * so saved funnels are no longer used.
     */
    public function up(): void
    {
        Schema::dropIfExists('funnel_steps');
        Schema::dropIfExists('funnels');
    }

    public function down(): void
    {
        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ga_property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_open')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('funnel_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained()->cascadeOnDelete();
            $table->integer('order');
            $table->string('name');
            $table->string('event_name');
            $table->json('conditions')->nullable();
            $table->timestamps();

            $table->unique(['funnel_id', 'order']);
        });
    }
};
