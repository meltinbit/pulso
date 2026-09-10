<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_search_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained('ga_properties')->cascadeOnDelete();
            $table->date('date');
            $table->string('query');
            $table->string('page', 1024)->nullable();
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);
            $table->decimal('ctr', 5, 2)->default(0);
            $table->decimal('position', 5, 1)->default(0);
            $table->timestamps();

            $table->index(['ga_property_id', 'date']);
            $table->index(['ga_property_id', 'date', 'clicks']);
            $table->index(['ga_property_id', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_search_queries');
    }
};
