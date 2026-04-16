<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_snapshot_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_snapshot_id')->constrained('property_snapshots')->cascadeOnDelete();
            $table->string('page_path');
            $table->string('page_title')->nullable();
            $table->integer('pageviews')->default(0);
            $table->integer('users')->default(0);
            $table->decimal('bounce_rate', 5, 2)->nullable();
            $table->integer('avg_engagement_time')->default(0);
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['property_snapshot_id', 'pageviews']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_snapshot_pages');
    }
};
