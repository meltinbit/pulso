<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_snapshot_search_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_snapshot_id')->constrained('property_snapshots')->cascadeOnDelete();
            $table->string('query');
            $table->string('page')->nullable();
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);
            $table->decimal('ctr', 5, 2)->default(0);
            $table->decimal('position', 5, 1)->default(0);
            $table->timestamps();

            $table->index(['property_snapshot_id', 'clicks']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_snapshot_search_queries');
    }
};
