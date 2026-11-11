<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AiVisibilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Weekly GEO prompt tracking: asks the tracked prompts to ChatGPT for every
 * user with an OpenAI key, within the monthly cap.
 */
class RunAiVisibilityChecks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public ?int $propertyId = null,
        public bool $force = false,
    ) {}

    public function handle(AiVisibilityService $aiVisibility): void
    {
        User::each(function (User $user) use ($aiVisibility): void {
            $result = $aiVisibility->run($user, $this->propertyId, $this->force);

            if ($result['status'] !== 'no_api_key') {
                Log::info("AI visibility for user {$user->id}: ".json_encode($result));
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("RunAiVisibilityChecks job failed: {$exception->getMessage()}");
    }
}
