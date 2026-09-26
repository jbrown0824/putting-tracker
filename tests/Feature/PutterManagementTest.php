<?php

use App\Enums\PutterHeadType;
use App\Enums\PuttResult;
use App\Models\Putter;
use App\Models\User;

beforeEach(fn () => $this->actingAs(testUser()));

it('lists the player\'s putters on the settings page', function () {
    blade()->update(['is_default' => true]);
    mallet()->update(['retired_at' => now()]);

    $this->get(route('settings'))
        ->assertOk()
        ->assertSee('Blade')
        ->assertSee('Default')
        ->assertSee('Retired');
});

it('adds a putter', function () {
    $this->post(route('putters.store'), [
        'name' => 'Spider X',
        'head_type' => 'mallet',
        'brand' => 'TaylorMade',
        'length_in' => 34,
    ])->assertRedirect(route('settings'));

    $putter = testUser()->putters()->sole();

    expect($putter->name)->toBe('Spider X')
        ->and($putter->head_type)->toBe(PutterHeadType::Mallet)
        ->and($putter->length_in)->toBe(34.0)
        // The only putter becomes the default without being asked.
        ->and($putter->is_default)->toBeTrue();
});

it('keeps exactly one default putter', function () {
    blade()->update(['is_default' => true]);

    $this->post(route('putters.store'), ['name' => 'Spider X', 'head_type' => 'mallet', 'is_default' => '1']);

    expect(testUser()->putters()->where('is_default', true)->pluck('name')->all())->toBe(['Spider X']);
});

it('retires a putter and hands the default to another', function () {
    blade()->update(['is_default' => true]);
    mallet();

    $this->put(route('putters.update', blade()), [
        'name' => 'Blade',
        'head_type' => 'blade',
        'is_default' => '1',
        'retired' => '1',
    ])->assertRedirect(route('settings'));

    expect(blade()->isRetired())->toBeTrue()
        ->and(blade()->is_default)->toBeFalse()
        ->and(mallet()->is_default)->toBeTrue()
        ->and(testUser()->defaultPutter()->id)->toBe(mallet()->id);
});

it('leaves a retired putter out of the logger', function () {
    blade();
    mallet()->update(['retired_at' => now()]);

    $this->get(route('log'))->assertOk()->assertSee('Blade')->assertDontSee('Mallet');
});

it('deletes a putter nothing was hit with', function () {
    $this->delete(route('putters.destroy', mallet()))->assertRedirect(route('settings'));

    expect(Putter::query()->count())->toBe(0);
});

it('refuses to delete a putter with history', function () {
    logPutts(3, PuttResult::Sunk, 10, putter: blade());

    $this->delete(route('putters.destroy', blade()))->assertSessionHasErrors('putter');

    expect(blade()->exists)->toBeTrue();
});

it('hides other players\' putters', function () {
    $theirs = blade(User::factory()->create());

    $this->get(route('putters.edit', $theirs))->assertNotFound();
    $this->put(route('putters.update', $theirs), ['name' => 'Mine now', 'head_type' => 'blade'])->assertNotFound();
    $this->delete(route('putters.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Blade');
});

it('validates the putter form', function () {
    $this->post(route('putters.store'), ['name' => '', 'head_type' => 'broomstick'])
        ->assertSessionHasErrors(['name', 'head_type']);
});
