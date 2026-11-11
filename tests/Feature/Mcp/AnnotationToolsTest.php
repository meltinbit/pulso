<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\AddAnnotationTool;
use App\Mcp\Tools\GetPropertyBingTrafficTool;
use App\Mcp\Tools\GetPropertySearchQueriesTool;
use App\Mcp\Tools\GetPropertySnapshotsTool;
use App\Mcp\Tools\GetPropertySummaryTool;
use App\Mcp\Tools\ListAnnotationsTool;
use App\Models\GaProperty;
use App\Models\PropertyAnnotation;
use App\Models\PropertySnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 10:00:00');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create([
        'display_name' => 'Calcolato',
        'website_url' => 'https://www.example.com',
        'timezone' => 'Europe/Rome',
    ]);
});

it('adds an annotation with full urls and the calling agent', function () {
    request()->query->set('agent', 'seo');

    PulsoServer::tool(AddAnnotationTool::class, [
        'property_id' => $this->property->id,
        'type' => 'content',
        'description' => 'Rewritten IMU calculator intro',
        'urls' => ['/imu/', 'https://www.example.com/imu/', 'https://www.example.com/tasse/'],
    ])->assertOk()->assertSee('"created":true');

    $annotation = PropertyAnnotation::sole();

    expect($annotation->date->toDateString())->toBe('2026-11-11')
        ->and($annotation->type)->toBe('content')
        ->and($annotation->urls)->toBe(['https://www.example.com/imu/', 'https://www.example.com/tasse/'])
        ->and($annotation->agent)->toBe('seo');
});

it('does not duplicate the same annotation', function () {
    $arguments = ['property_id' => $this->property->id, 'date' => '2026-11-05', 'type' => 'deploy', 'description' => 'Release 2.3'];

    PulsoServer::tool(AddAnnotationTool::class, $arguments)->assertOk();
    PulsoServer::tool(AddAnnotationTool::class, $arguments)->assertOk()->assertSee('"duplicate":true');

    expect(PropertyAnnotation::count())->toBe(1);
});

it('validates the annotation type', function () {
    PulsoServer::tool(AddAnnotationTool::class, [
        'property_id' => $this->property->id,
        'type' => 'gossip',
        'description' => 'Nope',
    ])->assertHasErrors();
});

it('cannot annotate another users property', function () {
    $other = GaProperty::factory()->create();

    expect(fn () => PulsoServer::tool(AddAnnotationTool::class, ['property_id' => $other->id, 'type' => 'content', 'description' => 'x']))
        ->toThrow(ModelNotFoundException::class);
});

it('lists annotations by site, range, type and text', function () {
    $other = GaProperty::factory()->for($this->user)->create(['display_name' => 'Altro sito']);
    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['date' => '2026-11-01', 'type' => 'content', 'description' => 'New IMU guide']);
    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['date' => '2026-10-20', 'type' => 'technical', 'description' => 'Fixed canonical tags']);
    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['date' => '2026-01-01', 'type' => 'content', 'description' => 'Too old']);
    PropertyAnnotation::factory()->for($other, 'gaProperty')->create(['date' => '2026-11-02', 'type' => 'campaign', 'description' => 'Google Ads launch']);
    PropertyAnnotation::factory()->create(['date' => '2026-11-02', 'description' => 'Someone else']);

    PulsoServer::tool(ListAnnotationsTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"count":2')
        ->assertSee('New IMU guide')
        ->assertDontSee('Too old')
        ->assertDontSee('Google Ads launch');

    PulsoServer::tool(ListAnnotationsTool::class, ['type' => 'content', 'contains' => 'imu'])
        ->assertSee('"count":1')
        ->assertSee('"property":"Calcolato"');

    PulsoServer::tool(ListAnnotationsTool::class, [])
        ->assertSee('Altro sito')
        ->assertDontSee('Someone else');
});

it('returns the annotations of the range with trend data', function () {
    PropertySnapshot::factory()->for($this->property, 'gaProperty')->create(['snapshot_date' => '2026-11-03']);
    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['date' => '2026-11-02', 'type' => 'deploy', 'description' => 'New template']);
    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['date' => '2026-08-01', 'description' => 'Out of range']);

    foreach ([GetPropertySnapshotsTool::class, GetPropertySearchQueriesTool::class] as $tool) {
        PulsoServer::tool($tool, ['property_id' => $this->property->id, 'from' => '2026-11-01', 'to' => '2026-11-10'])
            ->assertOk()
            ->assertSee('New template')
            ->assertDontSee('Out of range');
    }

    PulsoServer::tool(GetPropertySummaryTool::class, ['property_id' => $this->property->id])
        ->assertSee('annotations_30d')
        ->assertSee('New template');

    $this->property->update(['bing_site_url' => 'https://www.example.com/']);

    PulsoServer::tool(GetPropertyBingTrafficTool::class, ['property_id' => $this->property->id, 'from' => '2026-11-01', 'to' => '2026-11-10'])
        ->assertSee('New template');
});
