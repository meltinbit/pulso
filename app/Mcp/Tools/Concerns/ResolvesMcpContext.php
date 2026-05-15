<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\GaProperty;
use App\Models\User;

trait ResolvesMcpContext
{
    protected function currentUser(): User
    {
        abort_unless(auth()->check(), 401);

        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function resolveAuthorizedProperty(int $propertyId): GaProperty
    {
        return $this->currentUser()->gaProperties()->findOrFail($propertyId);
    }
}
