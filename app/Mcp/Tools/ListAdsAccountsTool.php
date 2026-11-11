<?php

namespace App\Mcp\Tools;

use App\Exceptions\GoogleAdsApiException;
use App\Services\GoogleAdsReportService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the Google Ads accounts Pulso can read (the accounts under the manager account): id, name, whether it is a manager, status and currency. Use the id as customer_id in the other Ads tools; without customer_id they use the default account.')]
#[IsReadOnly]
#[IsIdempotent]
class ListAdsAccountsTool extends Tool
{
    public function __construct(private GoogleAdsReportService $reports) {}

    public function handle(Request $request): Response
    {
        try {
            $accounts = $this->reports->accounts();
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'cols' => ['id', 'name', 'manager', 'status', 'currency'],
            'rows' => array_map(fn (array $account): array => array_values($account), $accounts),
        ]));
    }
}
