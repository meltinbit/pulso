<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_adsense_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained('ga_properties')->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension', 20);
            $table->string('dimension_value', 191)->default('');
            $table->decimal('earnings', 12, 4)->default(0);
            $table->unsignedBigInteger('page_views')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('ad_requests')->default(0);
            $table->unsignedBigInteger('matched_ad_requests')->default(0);
            $table->timestamps();

            $table->index(['ga_property_id', 'dimension', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_adsense_metrics');
    }
};
