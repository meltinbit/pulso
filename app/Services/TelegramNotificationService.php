<?php

namespace App\Services;

use App\Models\PropertySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    /**
     * Send the daily digest to Telegram.
     *
     * @param  Collection<int, PropertySnapshot>  $snapshots
     */
    public function sendDailyDigest(Collection $snapshots, Carbon $date): bool
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            Log::warning('Telegram credentials not configured, skipping daily digest.');

            return false;
        }

        if ($snapshots->isEmpty()) {
            return false;
        }

        $message = $this->buildMessage($snapshots, $date);

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        if (! $response->successful()) {
            Log::warning('Failed to send Telegram digest: '.$response->body());

            return false;
        }

        return true;
    }

    /**
     * Build the Markdown message for the daily digest.
     *
     * @param  Collection<int, PropertySnapshot>  $snapshots
     */
    public function buildMessage(Collection $snapshots, Carbon $date): string
    {
        $sorted = $snapshots->sortByDesc('trend_score');
        $dateFormatted = $date->format('d M');

        $lines = [];
        $lines[] = "\xF0\x9F\x93\x8A *Pulso \xE2\x80\x94 Daily Report \xC2\xB7 {$dateFormatted}*";
        $lines[] = '';

        foreach ($sorted as $snapshot) {
            $snapshot->loadMissing('gaProperty');
            $propertyName = $snapshot->gaProperty->display_name ?? $snapshot->gaProperty->property_id;
            $emoji = $this->trendEmoji($snapshot->trend);
            $trend = str_pad($snapshot->trend ?? 'stall', 8);
            $delta = $this->formatDelta($snapshot->users_delta_wow);

            $lines[] = "{$emoji} {$trend} \xC2\xB7 {$propertyName}     {$delta} utenti";
        }

        $topMover = $sorted->first();
        $bottomMover = $sorted->last();

        if ($topMover && $bottomMover && $sorted->count() > 1) {
            $lines[] = '';
            $lines[] = '*Top mover oggi:*';

            $topMover->loadMissing('gaProperty');
            $topName = $topMover->gaProperty->display_name ?? $topMover->gaProperty->property_id;
            $lines[] = "\xE2\x86\x91 {$topName} \xE2\x80\x94 {$topMover->users} utenti ({$this->formatDeltaAbsolute($topMover)})";

            $bottomMover->loadMissing('gaProperty');
            $bottomName = $bottomMover->gaProperty->display_name ?? $bottomMover->gaProperty->property_id;
            $lines[] = "\xE2\x86\x93 {$bottomName} \xE2\x80\x94 {$bottomMover->users} utenti ({$this->formatDeltaAbsolute($bottomMover)})";
        }

        return implode("\n", $lines);
    }

    private function trendEmoji(string $trend): string
    {
        return match ($trend) {
            'spike', 'improved' => "\xF0\x9F\x9F\xA2",
            'stall' => "\xE2\x9A\xAA",
            'declined' => "\xF0\x9F\x9F\xA1",
            'drop' => "\xF0\x9F\x94\xB4",
            default => "\xE2\x9A\xAA",
        };
    }

    private function formatDelta(?float $delta): string
    {
        if ($delta === null) {
            return 'N/A';
        }

        $sign = $delta >= 0 ? '+' : '';

        return "{$sign}{$delta}%";
    }

    private function formatDeltaAbsolute(PropertySnapshot $snapshot): string
    {
        if ($snapshot->users_delta_wow === null) {
            return 'N/A';
        }

        $wowDelta = $snapshot->users_delta_wow;
        $sign = $wowDelta >= 0 ? '+' : '';

        return "{$sign}{$wowDelta}% vs settimana scorsa";
    }
}
