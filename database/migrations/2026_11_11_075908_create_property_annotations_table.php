<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Change log of each site: what was published, fixed, launched or
     * deployed on a day, so trends can be read against their causes.
     */
    public function up(): void
    {
        Schema::create('property_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained('ga_properties')->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 20);
            $table->string('description', 500);
            $table->json('urls')->nullable();
            $table->string('agent', 100)->nullable();
            $table->timestamps();

            $table->index(['ga_property_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_annotations');
    }
};
