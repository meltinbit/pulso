<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_snapshots', function (Blueprint $table) {
            $table->decimal('pages_per_session', 5, 2)->nullable()->after('avg_session_duration');
            $table->integer('engaged_sessions')->nullable()->after('pages_per_session');
            $table->decimal('engagement_rate', 5, 2)->nullable()->after('engaged_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('property_snapshots', function (Blueprint $table) {
            $table->dropColumn(['pages_per_session', 'engaged_sessions', 'engagement_rate']);
        });
    }
};
