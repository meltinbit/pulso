<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_research_cache', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->string('operation', 50);
            $table->json('params');
            $table->json('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_research_cache');
    }
};
