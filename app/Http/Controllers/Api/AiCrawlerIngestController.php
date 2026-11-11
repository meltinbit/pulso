<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCrawlerVisit;
use App\Models\GaProperty;
use App\Services\AiCrawlers;
use App\Services\AiCrawlerVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receives AI crawler visits from the sites (one token per property) and
 * adds them to the daily counts. Unknown user agents are ignored, so the
 * list of crawlers lives only here. When the site sends the visitor IP,
 * each visit is checked against the vendor's published ranges: spoofed
 * visits are still counted, separately, so the agents can leave them out.
 */
class AiCrawlerIngestController extends Controller
{
    public function __invoke(Request $request, AiCrawlerVerifier $verifier): JsonResponse
    {
        $token = (string) $request->bearerToken();
        $property = $token === '' ? null : GaProperty::where('crawler_token_hash', hash('sha256', $token))->first();

        abort_if($property === null, 401, 'Invalid crawler token.');

        $validated = $request->validate([
            'hits' => 'required|array|min:1|max:200',
            'hits.*.user_agent' => 'required|string|max:1000',
            'hits.*.path' => 'required|string|max:4096',
            'hits.*.status' => 'nullable|integer|min:100|max:599',
            'hits.*.at' => 'nullable|date',
            'hits.*.ip' => 'nullable|ip',
        ]);

        $timezone = $property->timezone ?: 'UTC';
        $visits = [];
        $ignored = 0;

        foreach ($validated['hits'] as $hit) {
            $bot = AiCrawlers::identify($hit['user_agent']);

            if ($bot === null) {
                $ignored++;

                continue;
            }

            $at = isset($hit['at']) ? Carbon::parse($hit['at']) : now();
            $at = $at->isFuture() ? now() : $at;
            $path = $this->normalizePath($hit['path']);
            $key = $at->copy()->setTimezone($timezone)->toDateString().'|'.$bot.'|'.$path;

            $visits[$key] ??= ['date' => $at->copy()->setTimezone($timezone)->toDateString(), 'bot' => $bot, 'path' => $path, 'hits' => 0, 'verified' => 0, 'spoofed' => 0, 'status' => null, 'at' => $at];
            $visits[$key]['hits']++;

            match ($verifier->verify($bot, $hit['ip'] ?? null)) {
                true => $visits[$key]['verified']++,
                false => $visits[$key]['spoofed']++,
                null => null,
            };

            if ($at->gte($visits[$key]['at']) || $visits[$key]['status'] === null) {
                $visits[$key]['at'] = $at;
                $visits[$key]['status'] = $hit['status'] ?? $visits[$key]['status'];
            }
        }

        DB::transaction(function () use ($property, $visits): void {
            foreach ($visits as $visit) {
                $row = AiCrawlerVisit::query()
                    ->where('ga_property_id', $property->id)
                    ->whereDate('date', $visit['date'])
                    ->where('bot', $visit['bot'])
                    ->where('path_hash', sha1($visit['path']))
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    AiCrawlerVisit::create([
                        'ga_property_id' => $property->id,
                        'date' => $visit['date'],
                        'bot' => $visit['bot'],
                        'path' => $visit['path'],
                        'path_hash' => sha1($visit['path']),
                        'hits' => $visit['hits'],
                        'verified_hits' => $visit['verified'],
                        'spoofed_hits' => $visit['spoofed'],
                        'last_status' => $visit['status'],
                        'last_seen_at' => $visit['at'],
                    ]);

                    continue;
                }

                $row->hits += $visit['hits'];
                $row->verified_hits += $visit['verified'];
                $row->spoofed_hits += $visit['spoofed'];

                if ($visit['at']->gte($row->last_seen_at)) {
                    $row->last_seen_at = $visit['at'];
                    $row->last_status = $visit['status'] ?? $row->last_status;
                }

                $row->save();
            }
        });

        return response()->json([
            'accepted' => array_sum(array_column($visits, 'hits')),
            'verified' => array_sum(array_column($visits, 'verified')),
            'spoofed' => array_sum(array_column($visits, 'spoofed')),
            'ignored' => $ignored,
        ], 202);
    }

    /**
     * Path without query string or fragment, so tracking parameters do not
     * split one page into many rows.
     */
    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';

        return Str::limit('/'.ltrim($path, '/'), 1024, '');
    }
}
