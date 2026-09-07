<?php

use App\Enums\ClockPosition;
use App\Enums\PuttSlope;
use App\Models\Putt;
use App\Models\PuttingSession;

/**
 * Production had 58 putts carrying break_direction when it looked dead locally.
 * These cover recovering them without touching anything else.
 */
function legacyPutt(?string $break, ?string $slope): Putt
{
    $putt = Putt::factory()->create(['putting_session_id' => PuttingSession::factory()]);

    // break_direction is no longer fillable, so it is set the only way left.
    Putt::query()->whereKey($putt->id)->update([
        'break_direction' => $break,
        'slope' => $slope,
        'clock_position' => null,
    ]);

    return $putt->fresh();
}

it('reports what it would recover without writing anything', function () {
    legacyPutt('right_to_left', 'uphill');

    $this->artisan('putts:backfill-positions')
        ->expectsOutputToContain('Would recover 1')
        ->assertSuccessful();

    expect(Putt::first()->clock_position)->toBeNull();
});

it('recovers positions from the old pair of fields', function () {
    legacyPutt('right_to_left', 'downhill');
    legacyPutt('left_to_right', 'uphill');
    legacyPutt('straight', 'flat');

    $this->artisan('putts:backfill-positions', ['--force' => true])->assertSuccessful();

    expect(Putt::query()->pluck('clock_position')->all())->toBe([
        ClockPosition::AboveRight,
        ClockPosition::BelowLeft,
        ClockPosition::Flat,
    ]);
});

it('makes the recovered slope agree with the position', function () {
    legacyPutt('right_to_left', 'downhill');

    $this->artisan('putts:backfill-positions', ['--force' => true])->assertSuccessful();

    expect(Putt::first()->slope)->toBe(PuttSlope::Downhill);
});

it('leaves a putt alone when its position cannot be pinned down', function () {
    legacyPutt('right_to_left', null);

    $this->artisan('putts:backfill-positions', ['--force' => true])
        ->expectsOutputToContain('cannot be pinned down')
        ->assertSuccessful();

    expect(Putt::first()->clock_position)->toBeNull();
});

it('never overwrites a position that was recorded deliberately', function () {
    $putt = legacyPutt('right_to_left', 'uphill');
    Putt::query()->whereKey($putt->id)->update(['clock_position' => ClockPosition::Above]);

    $this->artisan('putts:backfill-positions', ['--force' => true])->assertSuccessful();

    expect(Putt::first()->clock_position)->toBe(ClockPosition::Above);
});
