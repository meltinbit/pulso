<?php

namespace App\Mcp\Tools;

use App\Exceptions\MetaAdsApiException;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\MetaAdsService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Meta (Facebook/Instagram) ad accounts readable by Pulso: ID, name, status, currency and time zone. Use the ID as account_id in the other Meta tools; without it they use the default account chosen in the settings.')]
#[IsReadOnly]
#[IsIdempotent]
class ListMetaAdAccountsTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(private MetaAdsService $metaAds) {}

    public function handle(Request $request): Response
    {
        try {
            $accounts = $this->metaAds->accounts($this->currentUser());
        } catch (MetaAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'cols' => ['id', 'name', 'status', 'currency', 'timezone'],
            'rows' => array_map(fn (array $account): array => array_values($account), $accounts),
        ]));
    }
}
