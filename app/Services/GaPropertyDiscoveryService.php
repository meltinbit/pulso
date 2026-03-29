<?php

namespace App\Services;

use App\Models\GaConnection;
use Illuminate\Support\Facades\Http;

class GaPropertyDiscoveryService
{
    public function __construct(
        private GoogleTokenService $tokenService,
    ) {}

    /** @return array<int, array{property_id: string, display_name: string, website_url: ?string, timezone: string, currency: string}> */
    public function listAccessibleProperties(GaConnection $connection): array
    {
        $token = $this->tokenService->getFreshToken($connection);

        $response = Http::withToken($token)
            ->timeout(15)
            ->connectTimeout(5)
            ->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries', [
                'pageSize' => 200,
            ]);

        if ($response->failed()) {
            return [];
        }

        $properties = [];

        foreach ($response->json('accountSummaries', []) as $account) {
            $accountName = $account['displayName'] ?? '';

            foreach ($account['propertySummaries'] ?? [] as $prop) {
                $properties[] = [
                    'property_id' => str_replace('properties/', '', $prop['property']),
                    'display_name' => $prop['displayName'],
                    'account_name' => $accountName,
                    'website_url' => null,
                    'timezone' => 'Europe/Rome',
                    'currency' => 'EUR',
                ];
            }
        }

        return $properties;
    }
}
