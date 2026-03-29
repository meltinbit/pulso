<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analytics_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained()->cascadeOnDelete();
            $table->string('cache_key', 64)->index();
            $table->string('report_type');
            $table->json('payload');
            $table->json('params')->nullable();
            $table->integer('tokens_used')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['ga_property_id', 'cache_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_cache');
    }
};
