<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_properties', function (Blueprint $table) {
            $table->string('crawler_token_hash', 64)->nullable()->unique()->after('ai_brand_terms');
        });

        Schema::create('ai_crawler_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('bot', 40);
            $table->string('path', 1024);
            $table->char('path_hash', 40);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->dateTime('last_seen_at');
            $table->timestamps();

            $table->unique(['ga_property_id', 'date', 'bot', 'path_hash'], 'ai_crawler_visits_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_crawler_visits');

        Schema::table('ga_properties', function (Blueprint $table) {
            $table->dropUnique(['crawler_token_hash']);
            $table->dropColumn('crawler_token_hash');
        });
    }
};
