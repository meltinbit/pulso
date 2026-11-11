<?php

namespace App\Console\Commands;

use App\Jobs\SyncBingWebmasterData;
use Illuminate\Console\Command;

class SyncBingCommand extends Command
{
    protected $signature = 'pulso:sync-bing {--property= : Sync only this property ID}';

    protected $description = 'Link properties to Bing Webmaster Tools sites and download Bing traffic, query, page and crawl stats';

    public function handle(): int
    {
        $propertyId = $this->option('property') ? (int) $this->option('property') : null;

        SyncBingWebmasterData::dispatchSync($propertyId);

        $this->info('Bing sync finished: see the log for details.');

        return self::SUCCESS;
    }
}
