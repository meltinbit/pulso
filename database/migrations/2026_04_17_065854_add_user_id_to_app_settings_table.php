<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['key']);
            $table->unique(['user_id', 'key']);
        });

        // Assign existing settings to the first user
        $firstUserId = DB::table('users')->min('id');
        if ($firstUserId) {
            DB::table('app_settings')->whereNull('user_id')->update(['user_id' => $firstUserId]);
        }
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'key']);
            $table->unique('key');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
