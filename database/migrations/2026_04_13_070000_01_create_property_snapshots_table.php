<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained('ga_properties')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('period')->default('daily');
            $table->integer('users')->default(0);
            $table->integer('sessions')->default(0);
            $table->integer('pageviews')->default(0);
            $table->decimal('bounce_rate', 5, 2)->default(0);
            $table->integer('avg_session_duration')->default(0);
            $table->json('top_sources')->nullable();
            $table->decimal('users_delta_wow', 6, 2)->nullable();
            $table->decimal('sessions_delta_wow', 6, 2)->nullable();
            $table->decimal('pageviews_delta_wow', 6, 2)->nullable();
            $table->decimal('bounce_delta_wow', 6, 2)->nullable();
            $table->decimal('users_delta_30d', 6, 2)->nullable();
            $table->decimal('sessions_delta_30d', 6, 2)->nullable();
            $table->string('trend')->nullable();
            $table->decimal('trend_score', 5, 2)->default(0);
            $table->boolean('is_spike')->default(false);
            $table->boolean('is_drop')->default(false);
            $table->boolean('is_stall')->default(false);
            $table->timestamps();

            $table->unique(['ga_property_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_snapshots');
    }
};
