<?php

use App\Models\GaProperty;
use App\Models\PropertyAnnotation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = GaProperty::factory()->for($this->user)->create();
});

it('lists the users annotations with filters', function () {
    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['type' => 'content', 'description' => 'Mine']);
    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['type' => 'deploy']);
    PropertyAnnotation::factory()->create(['description' => 'Not mine']);

    $this->actingAs($this->user)->get(route('annotations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('annotations/index')->has('annotations.data', 2));

    $this->actingAs($this->user)->get(route('annotations.index', ['type' => 'content', 'property' => $this->property->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('annotations.data', 1)
            ->where('annotations.data.0.description', 'Mine')
            ->where('filters.type', 'content'));
});

it('deletes only the users own annotations', function () {
    $mine = PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create();
    $theirs = PropertyAnnotation::factory()->create();

    $this->actingAs($this->user)->delete(route('annotations.destroy', $mine))->assertRedirect();
    $this->actingAs($this->user)->delete(route('annotations.destroy', $theirs))->assertForbidden();

    expect(PropertyAnnotation::whereKey($mine->id)->exists())->toBeFalse()
        ->and(PropertyAnnotation::whereKey($theirs->id)->exists())->toBeTrue();
});
