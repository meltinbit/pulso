<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_snapshot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_snapshot_id')->constrained('property_snapshots')->cascadeOnDelete();
            $table->string('event_name');
            $table->integer('event_count')->default(0);
            $table->integer('total_users')->default(0);
            $table->timestamps();

            $table->index(['property_snapshot_id', 'event_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_snapshot_events');
    }
};
