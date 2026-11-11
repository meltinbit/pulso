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

    /**
     * The agent calling the tool, named with ?agent= in the connector URL or
     * the X-Pulso-Agent header.
     */
    protected function currentAgent(): ?string
    {
        $agent = request()->query('agent') ?: request()->header('X-Pulso-Agent');

        return is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 100) : null;
    }

    protected function resolveAuthorizedProperty(int $propertyId): GaProperty
    {
        return $this->currentUser()->gaProperties()->findOrFail($propertyId);
    }
}
