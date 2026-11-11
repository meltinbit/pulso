<?php

namespace App\Services;

/**
 * The AI crawlers Pulso recognises, from their user agent, with who runs
 * them and what for: training (model training data), search (index used to
 * answer with sources) or user (a fetch made while a user asks a question).
 * Classic search engines are left out: Search Console and Bing cover them.
 * Source: Cloudflare AI Crawl Control bot reference and the vendors' docs;
 * IP lists and Amazon's DNS check verified online on 11/11/2026.
 */
class AiCrawlers
{
    private const OPENAI = 'https://openai.com/';

    private const GOOGLE = 'https://developers.google.com/static/crawling/ipranges/';

    /**
     * Each bot with how to verify it: "ips" lists the vendor's published IP
     * ranges (googlebot.json format), "dns" the domain its reverse DNS must
     * end with, null when the vendor publishes neither.
     *
     * @var array<string, array{operator: string, category: string, verify: array{ips?: list<string>, dns?: string}|null}>
     */
    public const BOTS = [
        'GPTBot' => ['operator' => 'OpenAI', 'category' => 'training', 'verify' => ['ips' => [self::OPENAI.'gptbot.json']]],
        'OAI-SearchBot' => ['operator' => 'OpenAI', 'category' => 'search', 'verify' => ['ips' => [self::OPENAI.'searchbot.json']]],
        'ChatGPT-User' => ['operator' => 'OpenAI', 'category' => 'user', 'verify' => ['ips' => [self::OPENAI.'chatgpt-user.json']]],
        'ClaudeBot' => ['operator' => 'Anthropic', 'category' => 'training', 'verify' => ['ips' => ['https://claude.com/crawling/bots.json']]],
        'Claude-SearchBot' => ['operator' => 'Anthropic', 'category' => 'search', 'verify' => ['ips' => ['https://claude.com/crawling/bots.json']]],
        'Claude-User' => ['operator' => 'Anthropic', 'category' => 'user', 'verify' => ['ips' => ['https://claude.com/crawling/bots.json']]],
        'PerplexityBot' => ['operator' => 'Perplexity', 'category' => 'search', 'verify' => ['ips' => ['https://www.perplexity.com/perplexitybot.json']]],
        'Perplexity-User' => ['operator' => 'Perplexity', 'category' => 'user', 'verify' => ['ips' => ['https://www.perplexity.com/perplexity-user.json']]],
        'Google-CloudVertexBot' => ['operator' => 'Google', 'category' => 'training', 'verify' => ['ips' => [self::GOOGLE.'common-crawlers.json']]],
        'meta-externalagent' => ['operator' => 'Meta', 'category' => 'training', 'verify' => null],
        'meta-externalfetcher' => ['operator' => 'Meta', 'category' => 'user', 'verify' => null],
        'Amazonbot' => ['operator' => 'Amazon', 'category' => 'training', 'verify' => ['dns' => 'crawl.amazonbot.amazon']],
        'Bytespider' => ['operator' => 'ByteDance', 'category' => 'training', 'verify' => null],
        'CCBot' => ['operator' => 'Common Crawl', 'category' => 'training', 'verify' => ['ips' => ['https://index.commoncrawl.org/ccbot.json']]],
        'DuckAssistBot' => ['operator' => 'DuckDuckGo', 'category' => 'user', 'verify' => ['ips' => ['https://duckduckgo.com/duckassistbot.json']]],
        'MistralAI-User' => ['operator' => 'Mistral', 'category' => 'user', 'verify' => ['ips' => ['https://mistral.ai/mistralai-user-ips.json']]],
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
