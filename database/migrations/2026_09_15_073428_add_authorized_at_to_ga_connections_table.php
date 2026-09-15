<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_connections', function (Blueprint $table) {
            $table->timestamp('authorized_at')->nullable()->after('is_active');
        });

        DB::table('ga_connections')->update(['authorized_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('ga_connections', function (Blueprint $table) {
            $table->dropColumn('authorized_at');
        });
    }
};
