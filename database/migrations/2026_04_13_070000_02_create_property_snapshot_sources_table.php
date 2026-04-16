<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_snapshot_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_snapshot_id')->constrained('property_snapshots')->cascadeOnDelete();
            $table->string('source');
            $table->string('medium');
            $table->integer('sessions')->default(0);
            $table->integer('users')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_snapshot_sources');
    }
};
