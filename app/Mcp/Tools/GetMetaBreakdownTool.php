<?php

namespace App\Mcp\Tools;

use App\Exceptions\MetaAdsApiException;
use App\Mcp\Tools\Concerns\FormatsMetaReport;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\MetaAdsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Meta (Facebook/Instagram) results split by audience or placement: age, gender, age_gender, country, region, placement (platform and position, e.g. instagram stories) or device. For the whole account or one campaign. Each row has cost, reach, impressions, link clicks, CTR (%), CPC, CPM, conversions, CPA and ROAS. Use it to find where the budget converts and where it is wasted. Defaults to the last 30 complete days; output is columnar, sorted by cost.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMetaBreakdownTool extends Tool
{
    use FormatsMetaReport, ResolvesMcpContext;

    /** @var array<string, list<string>> */
    private const BREAKDOWNS = [
        'age' => ['age'],
        'gender' => ['gender'],
        'age_gender' => ['age', 'gender'],
        'country' => ['country'],
        'region' => ['region'],
        'placement' => ['publisher_platform', 'platform_position'],
        'device' => ['device_platform'],
    ];

    public function __construct(private MetaAdsService $metaAds) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->metaCommonRules() + [
            'breakdown' => 'required|in:'.implode(',', array_keys(self::BREAKDOWNS)),
            'campaign_id' => 'nullable|string|max:30',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
        $user = $this->currentUser();
        $breakdowns = self::BREAKDOWNS[$validated['breakdown']];

        [$from, $to] = $this->adsRange($validated);

        try {
            $accountId = $this->metaAds->resolveAccountId($user, $validated['account_id'] ?? null);
            $rows = $this->metaAds->insights($user, $accountId, 'account', $from, $to, $breakdowns, $validated['campaign_id'] ?? null);
        } catch (MetaAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $action = $this->conversionAction($rows, $validated['conversion_action'] ?? null);
        $metrics = collect($rows)->map(fn (array $row): array => $row['attributes'] + $this->metaMetrics($row, $action));

        return Response::text(json_encode([
            'account_id' => $accountId,
            'campaign_id' => $validated['campaign_id'] ?? null,
            'breakdown' => $validated['breakdown'],
            'currency' => $rows[0]['currency'] ?? null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'conversion_action' => $action,
            'cols' => array_merge($breakdowns, ['cost', 'reach', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm', 'conversions', 'cpa', 'roas']),
            'rows' => $metrics
                ->sortByDesc('cost')
                ->take($validated['limit'] ?? 50)
                ->map(fn (array $row): array => array_merge(
                    array_map(fn (string $key): ?string => $row[$key] ?? null, $breakdowns),
                    [$row['cost'], $row['reach'], $row['impressions'], $row['clicks'], $row['ctr'], $row['cpc'], $row['cpm'], $row['conversions'], $row['cpa'], $row['roas']],
                ))
                ->values(),
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->metaCommonSchema($schema) + [
            'breakdown' => $schema->string()->enum(array_keys(self::BREAKDOWNS))
                ->description('How to split the results.')
                ->required(),
            'campaign_id' => $schema->string()
                ->description('Only this campaign (ID from get-meta-campaigns). Defaults to the whole account.'),
            'limit' => $schema->integer()
                ->description('Rows to return. Defaults to 50, maximum 200.'),
        ];
    }
}
