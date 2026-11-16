<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\GaConnection;
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

    /**
     * The active Google account to act with: the one with the given email, or
     * the only one connected. Returns an error message when it is ambiguous or
     * missing.
     */
    protected function resolveGoogleConnection(?string $email): GaConnection|string
    {
        $connections = $this->currentUser()->gaConnections()->where('is_active', true)->get();

        if ($email !== null && $email !== '') {
            return $connections->first(fn (GaConnection $connection): bool => strcasecmp($connection->google_email, $email) === 0)
                ?? "Nessun account Google attivo {$email} in Pulso. Account collegati: ".($connections->pluck('google_email')->implode(', ') ?: 'nessuno').'.';
        }

        return match ($connections->count()) {
            0 => 'Nessun account Google collegato: collegane uno in Impostazioni → Google di Pulso.',
            1 => $connections->first(),
            default => 'Più account Google collegati: indica google_account tra '.$connections->pluck('google_email')->implode(', ').'.',
        };
    }
}
