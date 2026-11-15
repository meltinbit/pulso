<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A property can now be a Search Console site without a GA4 property:
 * property_id becomes optional and gsc_site_url holds the exact Search
 * Console site (sc-domain:host or a URL prefix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_properties', function (Blueprint $table) {
            $table->string('property_id')->nullable()->change();
            $table->string('gsc_site_url')->nullable()->after('website_url');
            $table->unique(['user_id', 'gsc_site_url']);
        });
    }

    public function down(): void
    {
        Schema::table('ga_properties', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'gsc_site_url']);
            $table->dropColumn('gsc_site_url');
            $table->string('property_id')->nullable(false)->change();
        });
    }
};
