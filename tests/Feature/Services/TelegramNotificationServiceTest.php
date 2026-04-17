<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Services\SettingService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->connection = GaConnection::factory()->create();
    $this->property = GaProperty::factory()->create([
        'user_id' => $this->connection->user_id,
        'ga_connection_id' => $this->connection->id,
        'display_name' => 'Test Site',
    ]);

    app(SettingService::class)->set($this->connection->user_id, 'telegram_bot_token', 'test-bot-token', 'telegram', true);
    app(SettingService::class)->set($this->connection->user_id, 'telegram_chat_id', '12345', 'telegram');
});

test('sendDailyDigest sends message to Telegram', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    $snapshot = PropertySnapshot::factory()->create([
        'ga_property_id' => $this->property->id,
        'users' => 500,
        'users_delta_wow' => 25.0,
        'trend' => 'improved',
        'trend_score' => 20.0,
    ]);

    $service = app(TelegramNotificationService::class);
    $result = $service->sendDailyDigest(collect([$snapshot]), Carbon::yesterday());

    expect($result)->toBeTrue();
    Http::assertSentCount(1);
});

test('sendDailyDigest returns false when no credentials', function () {
    app(SettingService::class)->set($this->connection->user_id, 'telegram_bot_token', null, 'telegram');
    app(SettingService::class)->set($this->connection->user_id, 'telegram_chat_id', null, 'telegram');

    $snapshot = PropertySnapshot::factory()->create([
        'ga_property_id' => $this->property->id,
    ]);

    $service = app(TelegramNotificationService::class);
    $result = $service->sendDailyDigest(collect([$snapshot]), Carbon::yesterday());

    expect($result)->toBeFalse();
});

test('sendDailyDigest returns false for empty snapshots', function () {
    $service = app(TelegramNotificationService::class);
    $result = $service->sendDailyDigest(collect(), Carbon::yesterday());

    expect($result)->toBeFalse();
});

test('buildMessage includes property name and trend', function () {
    $snapshot = PropertySnapshot::factory()->create([
        'ga_property_id' => $this->property->id,
        'users' => 500,
        'users_delta_wow' => 25.0,
        'trend' => 'improved',
        'trend_score' => 20.0,
    ]);

    $service = app(TelegramNotificationService::class);
    $message = $service->buildMessage(collect([$snapshot]), Carbon::yesterday());

    expect($message)->toContain('Pulso');
    expect($message)->toContain('Daily Report');
    expect($message)->toContain('Test Site');
    expect($message)->toContain('improved');
    expect($message)->toContain('+25');
});

test('buildMessage sorts by trend_score descending', function () {
    $good = PropertySnapshot::factory()->create([
        'ga_property_id' => $this->property->id,
        'trend' => 'spike',
        'trend_score' => 80.0,
        'users_delta_wow' => 60.0,
    ]);

    $connection2 = GaConnection::factory()->create(['user_id' => $this->connection->user_id]);
    $property2 = GaProperty::factory()->create([
        'user_id' => $this->connection->user_id,
        'ga_connection_id' => $connection2->id,
        'display_name' => 'Bad Site',
    ]);
    $bad = PropertySnapshot::factory()->create([
        'ga_property_id' => $property2->id,
        'trend' => 'drop',
        'trend_score' => -50.0,
        'users_delta_wow' => -40.0,
    ]);

    $service = app(TelegramNotificationService::class);
    $message = $service->buildMessage(collect([$bad, $good]), Carbon::yesterday());

    $spikePos = strpos($message, 'spike');
    $dropPos = strpos($message, 'drop');
    expect($spikePos)->toBeLessThan($dropPos);
});

test('sendDailyDigest returns false on Telegram API failure', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false], 400),
    ]);

    $snapshot = PropertySnapshot::factory()->create([
        'ga_property_id' => $this->property->id,
    ]);

    $service = app(TelegramNotificationService::class);
    $result = $service->sendDailyDigest(collect([$snapshot]), Carbon::yesterday());

    expect($result)->toBeFalse();
});
