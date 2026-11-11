<?php

namespace App\Mcp\Tools;

use App\Exceptions\BingApiException;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\BingSyncService;
use App\Services\BingWebmasterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Ask Bing to crawl new or updated pages of a property right away (Bing Webmaster Tools URL submission), e.g. after a deploy. URLs must belong to the property\'s Bing site. Bing allows a limited number of submissions per site per day: the tool checks the remaining quota first and returns it after submitting. Use it only for pages that really changed.')]
class SubmitUrlsToBingTool extends Tool
{
    use ResolvesMcpContext;

    private const MAX_URLS = 100;

    public function __construct(
        private BingWebmasterService $bing,
        private BingSyncService $sync,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'urls' => 'required|array|min:1|max:'.self::MAX_URLS,
            'urls.*' => 'required|url|max:2048',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $apiKey = $this->sync->apiKey($this->currentUser());

        if ($apiKey === null || $property->bing_site_url === null) {
            return Response::error("{$property->display_name} non è collegata a Bing: salva la API key e collega il sito in Impostazioni → Bing del pannello di Pulso.");
        }

        $urls = array_values(array_unique($validated['urls']));
        $siteHost = BingSyncService::host($property->bing_site_url);
        $foreign = array_values(array_filter($urls, fn (string $url): bool => BingSyncService::host($url) !== $siteHost));

        if ($foreign !== []) {
            return Response::error("Questi URL non appartengono a {$property->bing_site_url}: ".implode(', ', $foreign));
        }

        try {
            $quota = $this->bing->urlSubmissionQuota($apiKey, $property->bing_site_url);

            if (count($urls) > $quota['daily']) {
                return Response::error('Quota Bing insufficiente: oggi restano '.$quota['daily'].' invii per questo sito, ne hai chiesti '.count($urls).'. Invia solo le pagine più importanti o riprova domani.');
            }

            $this->bing->submitUrlBatch($apiKey, $property->bing_site_url, $urls);
        } catch (BingApiException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'property' => $property->display_name,
            'submitted' => count($urls),
            'remaining_today' => $quota['daily'] - count($urls),
            'remaining_month' => $quota['monthly'] - count($urls),
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the property. Use list-properties to find it.')
                ->required(),
            'urls' => $schema->array()
                ->items($schema->string())
                ->description('Full URLs to submit, 1 to 100, all on the property\'s site.')
                ->required(),
        ];
    }
}
