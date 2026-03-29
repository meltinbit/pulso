<?php

namespace App\Policies;

use App\Models\Funnel;
use App\Models\User;

class FunnelPolicy
{
    public function view(User $user, Funnel $funnel): bool
    {
        return $user->id === $funnel->user_id;
    }

    public function update(User $user, Funnel $funnel): bool
    {
        return $user->id === $funnel->user_id;
    }

    public function delete(User $user, Funnel $funnel): bool
    {
        return $user->id === $funnel->user_id;
    }
}
