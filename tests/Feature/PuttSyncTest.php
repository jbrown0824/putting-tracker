<?php

use App\Actions\ResolvePuttingSession;
use App\Enums\ClockPosition;
use App\Enums\Putter;
use App\Enums\PuttSlope;
use App\Models\Challenge;
use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

function puttPayload(array $overrides = []): array
{
    return array_merge([
        'uuid' => (string) Str::uuid(),
        'distance_ft' => 10,
        'result' => 'sunk',
        'context' => 'inside',
        'hit_at' => Carbon::now()->toIso8601String(),
    ], $overrides);
}

it('stores a batch of putts', function () {
    Challenge::factory()->create();

    $response = $this->postJson(route('api.putts.sync'), [
        'putts' => [
            puttPayload(['distance_ft' => 5, 'result' => 'sunk']),
            puttPayload(['distance_ft' => 12, 'result' => 'miss_short']),
        ],
    ]);

    $response->assertOk();
    expect(Putt::count())->toBe(2);
    expect($response->json('progress.total'))->toBe(2);
});

it('is idempotent when a batch is replayed', function () {
    Challenge::factory()->create();
    $putt = puttPayload();

    $this->postJson(route('api.putts.sync'), ['putts' => [$putt]])->assertOk();
    $this->postJson(route('api.putts.sync'), ['putts' => [$putt]])->assertOk();

    expect(Putt::count())->toBe(1);
});

it('rejects an invalid result value', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['result' => 'holed_out'])],
    ])->assertJsonValidationErrorFor('putts.0.result');
});

it('groups consecutive putts of the same context into one session', function () {
    Challenge::factory()->create();
    $start = Carbon::parse('2026-08-23 10:00:00');

    $this->postJson(route('api.putts.sync'), [
        'putts' => [
            puttPayload(['hit_at' => $start->toIso8601String()]),
            puttPayload(['hit_at' => $start->copy()->addMinutes(5)->toIso8601String()]),
        ],
    ])->assertOk();

    expect(PuttingSession::count())->toBe(1);
});

it('starts a new session when the context changes', function () {
    Challenge::factory()->create();
    $start = Carbon::parse('2026-08-23 10:00:00');

    $this->postJson(route('api.putts.sync'), [
        'putts' => [
            puttPayload(['hit_at' => $start->toIso8601String(), 'context' => 'inside']),
            puttPayload(['hit_at' => $start->copy()->addMinutes(2)->toIso8601String(), 'context' => 'outside']),
        ],
    ])->assertOk();

    expect(PuttingSession::count())->toBe(2);
});

it('starts a new session after a long idle gap', function () {
    Challenge::factory()->create();
    $start = Carbon::parse('2026-08-23 10:00:00');
    $afterGap = $start->copy()->addMinutes(ResolvePuttingSession::IDLE_GAP_MINUTES + 30);

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['hit_at' => $start->toIso8601String()])],
    ])->assertOk();

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['hit_at' => $afterGap->toIso8601String()])],
    ])->assertOk();

    expect(PuttingSession::count())->toBe(2);
});

it('deletes a putt by uuid so undo works after syncing', function () {
    Challenge::factory()->create();
    $putt = puttPayload();

    $this->postJson(route('api.putts.sync'), ['putts' => [$putt]])->assertOk();
    $this->deleteJson(route('api.putts.destroy', $putt['uuid']))->assertOk();

    expect(Putt::count())->toBe(0);
});

it('stores the putter a putt was hit with', function () {
    Challenge::factory()->create();

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['putter' => 'mallet'])],
    ])->assertOk();

    expect(Putt::first()->putter)->toBe(Putter::Mallet)
        ->and(PuttingSession::first()->putter)->toBe(Putter::Mallet);
});

it('defaults to the blade when a client syncs without a putter', function () {
    Challenge::factory()->create();

    // A phone running a bundle from before putter tracking shipped. Its queued
    // putts still have to land rather than fail validation forever.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload()],
    ])->assertOk();

    expect(Putt::first()->putter)->toBe(Putter::Blade);
});

it('rejects an unknown putter', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['putter' => 'broomstick'])],
    ])->assertJsonValidationErrorFor('putts.0.putter');
});

it('starts a new session when the putter changes mid-practice', function () {
    Challenge::factory()->create();
    $start = Carbon::parse('2026-08-23 10:00:00');

    $this->postJson(route('api.putts.sync'), [
        'putts' => [
            puttPayload(['hit_at' => $start->toIso8601String(), 'putter' => 'blade']),
            puttPayload(['hit_at' => $start->copy()->addMinutes(2)->toIso8601String(), 'putter' => 'mallet']),
        ],
    ])->assertOk();

    expect(PuttingSession::count())->toBe(2)
        ->and(PuttingSession::pluck('putter')->all())->toEqualCanonicalizing([Putter::Blade, Putter::Mallet]);
});

it('stores the clock position and derives the slope from it', function () {
    Challenge::factory()->create();

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['clock_position' => 'above_right'])],
    ])->assertOk();

    $putt = Putt::first();

    expect($putt->clock_position)->toBe(ClockPosition::AboveRight)
        // Never sent by the client — the position already fixes it.
        ->and($putt->slope)->toBe(PuttSlope::Downhill);
});

it('stores a putt with no position rather than failing validation', function () {
    Challenge::factory()->create();

    // A phone running a bundle from before the ring shipped.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload()],
    ])->assertOk();

    expect(Putt::first()->clock_position)->toBeNull();
});

it('keeps a stale client\'s own slope when it sends no position', function () {
    Challenge::factory()->create();

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['slope' => 'uphill'])],
    ])->assertOk();

    expect(Putt::first()->slope)->toBe(PuttSlope::Uphill)
        ->and(Putt::first()->clock_position)->toBeNull();
});

it('lets the position override a slope the client also sent', function () {
    Challenge::factory()->create();

    // The two contradict each other; the position is the richer datum and wins.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['slope' => 'uphill', 'clock_position' => 'above'])],
    ])->assertOk();

    expect(Putt::first()->slope)->toBe(PuttSlope::Downhill);
});

it('rejects an unknown clock position', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['clock_position' => 'half_past_three'])],
    ])->assertJsonValidationErrorFor('putts.0.clock_position');
});

it('converts a stale client\'s slope and break into a position', function () {
    Challenge::factory()->create();

    // A phone running a bundle from before the ring existed. Its putts still land
    // classified rather than falling into the unclassified bucket.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['slope' => 'uphill', 'break_direction' => 'right_to_left'])],
    ])->assertOk();

    expect(Putt::first()->clock_position)->toBe(ClockPosition::BelowRight);
});

it('leaves a stale putt unclassified when it sent a break but no slope', function () {
    Challenge::factory()->create();

    // Three positions break right to left; picking one would be inventing data.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['break_direction' => 'right_to_left'])],
    ])->assertOk();

    expect(Putt::first()->clock_position)->toBeNull();
});
