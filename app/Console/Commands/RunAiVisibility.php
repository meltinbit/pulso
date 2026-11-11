<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AiVisibilityService;
use Illuminate\Console\Command;

class RunAiVisibility extends Command
{
    protected $signature = 'pulso:ai-visibility
        {--property= : Only the prompts of this property ID}
        {--force : Run even if the frequency setting says it is not due}';

    protected $description = 'Ask the tracked GEO prompts to ChatGPT and record whether the answers cite the sites (within the monthly cap)';

    public function handle(AiVisibilityService $aiVisibility): int
    {
        $propertyId = $this->option('property') ? (int) $this->option('property') : null;

        User::each(function (User $user) use ($aiVisibility, $propertyId): void {
            $result = $aiVisibility->run($user, $propertyId, (bool) $this->option('force'));

            $this->line(sprintf(
                'User %d: %s, %d checked, %d skipped for budget, %d errors, $%.4f spent this month.',
                $user->id,
                $result['status'],
                $result['checked'],
                $result['skipped_for_budget'],
                $result['errors'],
                $result['spent_month_usd'],
            ));
        });

        return self::SUCCESS;
    }
}
