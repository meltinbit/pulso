<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bing Webmaster Tools data. Daily traffic and crawl stats, weekly query and
     * page stats (Bing only publishes those per week, Friday to Thursday).
     */
    public function up(): void
    {
        Schema::table('ga_properties', function (Blueprint $table) {
            $table->string('bing_site_url')->nullable()->after('website_url');
        });

        Schema::create('property_bing_traffic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained('ga_properties')->cascadeOnDelete();
            $table->date('date');
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);

            $table->unique(['ga_property_id', 'date']);
        });

        Schema::create('property_bing_search_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained('ga_properties')->cascadeOnDelete();
            $table->string('dimension', 10);
            $table->date('week_start');
            $table->string('value', 1024);
            $table->integer('clicks')->default(0);
            $table->integer('impressions')->default(0);
            $table->decimal('avg_impression_position', 6, 1)->nullable();
            $table->decimal('avg_click_position', 6, 1)->nullable();

            $table->index(['ga_property_id', 'dimension', 'week_start'], 'bing_search_stats_lookup');
        });

        Schema::create('property_bing_crawl_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_property_id')->constrained('ga_properties')->cascadeOnDelete();
            $table->date('date');
            $table->integer('crawled_pages')->default(0);
            $table->integer('in_index')->default(0);
            $table->integer('in_links')->default(0);
            $table->integer('crawl_errors')->default(0);
            $table->integer('code_2xx')->default(0);
            $table->integer('code_301')->default(0);
            $table->integer('code_302')->default(0);
            $table->integer('code_4xx')->default(0);
            $table->integer('code_5xx')->default(0);
            $table->integer('blocked_by_robots_txt')->default(0);
            $table->integer('dns_failures')->default(0);
            $table->integer('connection_timeout')->default(0);
            $table->integer('contains_malware')->default(0);
            $table->integer('all_other_codes')->default(0);

            $table->unique(['ga_property_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_bing_crawl_stats');
        Schema::dropIfExists('property_bing_search_stats');
        Schema::dropIfExists('property_bing_traffic');

        Schema::table('ga_properties', function (Blueprint $table) {
            $table->dropColumn('bing_site_url');
        });
    }
};
