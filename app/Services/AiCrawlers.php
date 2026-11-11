<?php

namespace App\Services;

/**
 * The AI crawlers Pulso recognises, from their user agent, with who runs
 * them and what for: training (model training data), search (index used to
 * answer with sources) or user (a fetch made while a user asks a question).
 * Classic search engines are left out: Search Console and Bing cover them.
 * Source: Cloudflare AI Crawl Control bot reference and the vendors' docs (11/2026).
 */
class AiCrawlers
{
    /** @var array<string, array{operator: string, category: string}> */
    public const BOTS = [
        'GPTBot' => ['operator' => 'OpenAI', 'category' => 'training'],
        'OAI-SearchBot' => ['operator' => 'OpenAI', 'category' => 'search'],
        'ChatGPT-User' => ['operator' => 'OpenAI', 'category' => 'user'],
        'ClaudeBot' => ['operator' => 'Anthropic', 'category' => 'training'],
        'Claude-SearchBot' => ['operator' => 'Anthropic', 'category' => 'search'],
        'Claude-User' => ['operator' => 'Anthropic', 'category' => 'user'],
        'PerplexityBot' => ['operator' => 'Perplexity', 'category' => 'search'],
        'Perplexity-User' => ['operator' => 'Perplexity', 'category' => 'user'],
        'Google-CloudVertexBot' => ['operator' => 'Google', 'category' => 'training'],
        'meta-externalagent' => ['operator' => 'Meta', 'category' => 'training'],
        'meta-externalfetcher' => ['operator' => 'Meta', 'category' => 'user'],
        'Amazonbot' => ['operator' => 'Amazon', 'category' => 'training'],
        'Bytespider' => ['operator' => 'ByteDance', 'category' => 'training'],
        'CCBot' => ['operator' => 'Common Crawl', 'category' => 'training'],
        'DuckAssistBot' => ['operator' => 'DuckDuckGo', 'category' => 'user'],
        'MistralAI-User' => ['operator' => 'Mistral', 'category' => 'user'],
    ];

    public const CATEGORIES = ['training', 'search', 'user'];

    /**
     * Canonical bot name in a user agent, or null for anything else.
     */
    public static function identify(string $userAgent): ?string
    {
        foreach (array_keys(self::BOTS) as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return $bot;
            }
        }

        return null;
    }

    /**
     * The regular expression the sites use to decide what to report (bot
     * names are letters and dashes only, so nothing needs escaping).
     */
    public static function pattern(): string
    {
        return '/'.implode('|', array_keys(self::BOTS)).'/i';
    }
}
