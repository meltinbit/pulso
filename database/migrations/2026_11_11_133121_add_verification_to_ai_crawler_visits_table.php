<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_crawler_visits', function (Blueprint $table) {
            $table->unsignedInteger('verified_hits')->default(0)->after('hits');
            $table->unsignedInteger('spoofed_hits')->default(0)->after('verified_hits');
        });
    }

    public function down(): void
    {
        Schema::table('ai_crawler_visits', function (Blueprint $table) {
            $table->dropColumn(['verified_hits', 'spoofed_hits']);
        });
    }
};
