<?php

use App\Actions\ResolvePuttingSession;
use App\Enums\ClockPosition;
use App\Enums\PuttSlope;
use App\Enums\SurfaceType;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Putt;
use App\Models\PuttingSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(fn () => $this->actingAs(testUser()));

it('stores a batch of putts', function () {
    $response = $this->postJson(route('api.putts.sync'), [
        'putts' => [
            puttPayload(['distance_ft' => 5, 'result' => 'sunk']),
            puttPayload(['distance_ft' => 12, 'result' => 'miss_short']),
        ],
    ]);

    $response->assertOk();
    expect(Putt::count())->toBe(2)
        ->and(Putt::query()->pluck('user_id')->unique()->all())->toBe([testUser()->id])
        ->and($response->json('progress.today.total'))->toBe(2)
        ->and($response->json('progress.today.sunk'))->toBe(1);
});

it('is idempotent when a batch is replayed', function () {
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
    $putt = puttPayload();

    $this->postJson(route('api.putts.sync'), ['putts' => [$putt]])->assertOk();
    $this->deleteJson(route('api.putts.destroy', $putt['uuid']))->assertOk();

    expect(Putt::count())->toBe(0);
});

it('stores the putter a putt was hit with', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['putter_id' => mallet()->id])],
    ])->assertOk();

    expect(Putt::first()->putter_id)->toBe(mallet()->id)
        ->and(PuttingSession::first()->putter_id)->toBe(mallet()->id);
});

it('falls back to the default putter when a client syncs without one', function () {
    blade()->update(['is_default' => true]);
    mallet();

    // A phone running a bundle from before putters were per-player. Its queued
    // putts still have to land rather than fail validation forever.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['putter' => 'mallet'])],
    ])->assertOk();

    expect(Putt::first()->putter_id)->toBe(blade()->id);
});

it('falls back to the default putter rather than filing a putt against someone else\'s', function () {
    $stranger = User::factory()->create();
    $theirs = blade($stranger);
    blade()->update(['is_default' => true]);

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['putter_id' => $theirs->id])],
    ])->assertOk();

    expect(Putt::first()->putter_id)->toBe(blade()->id);
});

it('creates a putter for a player who has none rather than rejecting the putt', function () {
    $this->postJson(route('api.putts.sync'), ['putts' => [puttPayload()]])->assertOk();

    expect(testUser()->putters()->count())->toBe(1)
        ->and(Putt::first()->putter_id)->toBe(testUser()->putters()->first()->id);
});

it('starts a new session when the putter changes mid-practice', function () {
    $start = Carbon::parse('2026-08-23 10:00:00');

    $this->postJson(route('api.putts.sync'), [
        'putts' => [
            puttPayload(['hit_at' => $start->toIso8601String(), 'putter_id' => blade()->id]),
            puttPayload(['hit_at' => $start->copy()->addMinutes(2)->toIso8601String(), 'putter_id' => mallet()->id]),
        ],
    ])->assertOk();

    expect(PuttingSession::count())->toBe(2)
        ->and(PuttingSession::pluck('putter_id')->all())->toEqualCanonicalizing([blade()->id, mallet()->id]);
});

it('stores the surface type and splits the session when it changes', function () {
    $start = Carbon::parse('2026-08-23 10:00:00');

    $this->postJson(route('api.putts.sync'), [
        'putts' => [
            puttPayload(['hit_at' => $start->toIso8601String(), 'surface_type' => 'mat']),
            puttPayload(['hit_at' => $start->copy()->addMinutes(2)->toIso8601String(), 'surface_type' => 'carpet']),
        ],
    ])->assertOk();

    expect(Putt::query()->orderBy('hit_at')->pluck('surface_type')->all())->toBe([SurfaceType::Mat, SurfaceType::Carpet])
        ->and(PuttingSession::count())->toBe(2);
});

it('drops a surface type that contradicts the context', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['context' => 'inside', 'surface_type' => 'course_green'])],
    ])->assertOk();

    expect(Putt::first()->surface_type)->toBeNull();
});

it('rejects an unknown surface type', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['surface_type' => 'ice'])],
    ])->assertJsonValidationErrorFor('putts.0.surface_type');
});

it('never overwrites another player\'s putt that shares a uuid', function () {
    $stranger = User::factory()->create();
    $payload = puttPayload(['distance_ft' => 30]);

    $this->actingAs($stranger)->postJson(route('api.putts.sync'), ['putts' => [$payload]])->assertOk();
    $this->actingAs(testUser())->postJson(route('api.putts.sync'), ['putts' => [[...$payload, 'distance_ft' => 3]]])->assertOk();

    expect($stranger->putts()->first()->distance_ft)->toBe(30)
        ->and(testUser()->putts()->first()->distance_ft)->toBe(3);
});

it('only deletes the player\'s own putts', function () {
    $stranger = User::factory()->create();
    $payload = puttPayload();

    $this->actingAs($stranger)->postJson(route('api.putts.sync'), ['putts' => [$payload]])->assertOk();
    $this->actingAs(testUser())->deleteJson(route('api.putts.destroy', $payload['uuid']))->assertOk();

    expect($stranger->putts()->count())->toBe(1);
});

it('refuses to sync without a login', function () {
    auth()->logout();

    $this->postJson(route('api.putts.sync'), ['putts' => [puttPayload()]])->assertUnauthorized();

    expect(Putt::count())->toBe(0);
});

it('stores the clock position and derives the slope from it', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['clock_position' => 'above_right'])],
    ])->assertOk();

    $putt = Putt::first();

    expect($putt->clock_position)->toBe(ClockPosition::AboveRight)
        // Never sent by the client — the position already fixes it.
        ->and($putt->slope)->toBe(PuttSlope::Downhill);
});

it('stores a putt with no position rather than failing validation', function () {
    // A phone running a bundle from before the ring shipped.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload()],
    ])->assertOk();

    expect(Putt::first()->clock_position)->toBeNull();
});

it('keeps a stale client\'s own slope when it sends no position', function () {
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['slope' => 'uphill'])],
    ])->assertOk();

    expect(Putt::first()->slope)->toBe(PuttSlope::Uphill)
        ->and(Putt::first()->clock_position)->toBeNull();
});

it('lets the position override a slope the client also sent', function () {
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
    // A phone running a bundle from before the ring existed. Its putts still land
    // classified rather than falling into the unclassified bucket.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['slope' => 'uphill', 'break_direction' => 'right_to_left'])],
    ])->assertOk();

    expect(Putt::first()->clock_position)->toBe(ClockPosition::BelowRight);
});

it('leaves a stale putt unclassified when it sent a break but no slope', function () {
    // Three positions break right to left; picking one would be inventing data.
    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['break_direction' => 'right_to_left'])],
    ])->assertOk();

    expect(Putt::first()->clock_position)->toBeNull();
});

it('files drill putts under a run and marks it finished once the ladder is climbed', function () {
    $drill = Challenge::factory()->drill()->for(testUser())->create();
    foreach ([3, 4] as $index => $distance) {
        $drill->steps()->create(['sort_order' => $index, 'distance_ft' => $distance]);
    }
    $run = (string) Str::uuid();
    $start = Carbon::parse('2026-09-10 10:00:00');
    $drillPutt = fn (int $second, string $result, int $step): array => puttPayload([
        'hit_at' => $start->copy()->addSeconds($second)->toIso8601String(),
        'result' => $result,
        'challenge_id' => $drill->id,
        'challenge_run_uuid' => $run,
        'drill_step' => $step,
    ]);

    // Delivered out of order across two batches, as an offline phone might.
    $this->postJson(route('api.putts.sync'), ['putts' => [$drillPutt(30, 'sunk', 1)]])->assertOk();
    $this->postJson(route('api.putts.sync'), ['putts' => [$drillPutt(0, 'sunk', 0), $drillPutt(10, 'miss_short', 1), $drillPutt(20, 'sunk', 0)]])->assertOk();

    $stored = ChallengeRun::query()->sole();

    expect($stored->putts()->count())->toBe(4)
        ->and($stored->started_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and($stored->completed_at->toDateTimeString())->toBe('2026-09-10 10:00:30');
});

it('ignores a run that names someone else\'s drill but still keeps the putt', function () {
    $theirs = Challenge::factory()->drill()->create();

    $this->postJson(route('api.putts.sync'), ['putts' => [puttPayload([
        'challenge_id' => $theirs->id,
        'challenge_run_uuid' => (string) Str::uuid(),
        'drill_step' => 0,
    ])]])->assertOk();

    expect(Putt::query()->sole()->challenge_run_id)->toBeNull()
        ->and(ChallengeRun::query()->count())->toBe(0);
});

it('returns the progress of every challenge running today', function () {
    $challenge = challengeWith([], [['metric' => 'attempts', 'period' => 'daily', 'target' => 50]]);

    $response = $this->postJson(route('api.putts.sync'), ['putts' => [puttPayload(), puttPayload()]])->assertOk();

    expect($response->json('progress.challenges.0.id'))->toBe($challenge->id)
        ->and($response->json('progress.challenges.0.goals.0.current.value'))->toBe(2)
        ->and($response->json('progress.challenges.0.goals.0.current.remaining'))->toBe(48);
});
