<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tool', 100);
            $table->string('agent', 100)->nullable();
            $table->json('arguments')->nullable();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->boolean('is_error')->default(false);
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->index();

            $table->index(['tool', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_call_logs');
    }
};
