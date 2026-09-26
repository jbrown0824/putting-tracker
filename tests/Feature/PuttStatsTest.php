<?php

use App\Enums\BreakSide;
use App\Enums\ClockPosition;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use App\Models\Putt;
use App\Models\PuttingSession;
use App\Models\User;
use App\Services\PuttStats;

it('never counts another player\'s putts', function () {
    logPutts(10, PuttResult::Sunk, 10);
    logPutts(30, PuttResult::MissShort, 10, putter: blade(User::factory()->create()));

    expect(stats()->byDistance()->sum('attempts'))->toBe(10)
        ->and(stats()->missDial()['sunk']['percent'])->toBe(100.0);
});

it('refuses to query before it knows whose putts to read', function () {
    app(PuttStats::class)->missDial();
})->throws(LogicException::class);

it('splits misses into speed and line errors', function () {
    logPutts(30, PuttResult::MissShort, 10);
    logPutts(10, PuttResult::MissLong, 10);
    logPutts(10, PuttResult::MissLeft, 10);
    logPutts(10, PuttResult::MissRight, 10);
    logPutts(5, PuttResult::LipOut, 10);
    logPutts(20, PuttResult::Sunk, 10);

    $split = stats()->speedVsLine();

    expect($split['speed'])->toBe(40)
        ->and($split['line'])->toBe(20)
        ->and($split['lip_out'])->toBe(5)
        ->and($split['speed_percent'])->toBe(66.7);
});

it('builds the miss dial as shares of all putts', function () {
    logPutts(50, PuttResult::Sunk, 5);
    logPutts(50, PuttResult::MissShort, 5);

    $dial = stats()->missDial();

    expect($dial['sunk']['percent'])->toBe(50.0)
        ->and($dial['miss_short']['percent'])->toBe(50.0)
        ->and($dial['miss_long']['count'])->toBe(0);
});

it('reports make rate and speed bias per distance', function () {
    logPutts(8, PuttResult::Sunk, 5);
    logPutts(2, PuttResult::MissShort, 5);
    logPutts(2, PuttResult::Sunk, 20);
    logPutts(8, PuttResult::MissShort, 20);

    $rows = stats()->byDistance()->keyBy('distance_ft');

    expect($rows[5]['make_percent'])->toBe(80.0)
        ->and($rows[20]['make_percent'])->toBe(20.0)
        ->and($rows[20]['short'])->toBe(8)
        ->and($rows[20]['speed_bias'])->toBe(-100.0);
});

it('interpolates the distance where make rate crosses fifty percent', function () {
    logPutts(8, PuttResult::Sunk, 10);
    logPutts(2, PuttResult::MissShort, 10);
    logPutts(2, PuttResult::Sunk, 20);
    logPutts(8, PuttResult::MissShort, 20);

    expect(stats()->fiftyPercentDistance())->toBe(15.0);
});

it('returns null for the fifty percent distance without data on both sides', function () {
    logPutts(10, PuttResult::Sunk, 5);

    expect(stats()->fiftyPercentDistance())->toBeNull();
});

it('compares inside against outside at shared distances only', function () {
    logPutts(10, PuttResult::Sunk, 10, PuttContext::Inside);
    logPutts(5, PuttResult::Sunk, 10, PuttContext::Outside);
    logPutts(5, PuttResult::MissShort, 10, PuttContext::Outside);
    logPutts(10, PuttResult::Sunk, 30, PuttContext::Inside);

    $rows = stats()->insideVsOutside();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['distance_ft'])->toBe(10)
        ->and($rows[0]['inside_percent'])->toBe(100.0)
        ->and($rows[0]['outside_percent'])->toBe(50.0)
        ->and($rows[0]['gap'])->toBe(50.0);
});

it('asks for more data before drawing conclusions', function () {
    logPutts(5, PuttResult::Sunk, 5);

    expect(stats()->insights()[0])->toContain('25 putts');
});

it('calls out a dominant short miss tendency', function () {
    logPutts(40, PuttResult::MissShort, 15);
    logPutts(5, PuttResult::MissLong, 15);

    $insights = stats()->insights();

    expect(implode(' ', $insights))->toContain('short');
});

it('keeps each putter\'s performance stats completely isolated', function () {
    logPutts(20, PuttResult::Sunk, 10, PuttContext::Inside, blade());
    logPutts(20, PuttResult::MissShort, 10, PuttContext::Inside, mallet());

    $blade = stats()->forPutter(blade());
    $mallet = stats()->forPutter(mallet());

    expect($blade->byDistance()->sum('attempts'))->toBe(20)
        ->and($blade->missDial()['sunk']['percent'])->toBe(100.0)
        ->and($mallet->byDistance()->sum('attempts'))->toBe(20)
        ->and($mallet->missDial()['sunk']['percent'])->toBe(0.0)
        ->and($mallet->missDial()['miss_short']['percent'])->toBe(100.0);
});

it('scopes the speed and line split to the selected putter', function () {
    logPutts(10, PuttResult::MissShort, 10, PuttContext::Inside, blade());
    logPutts(10, PuttResult::MissLeft, 10, PuttContext::Inside, mallet());

    $blade = stats()->forPutter(blade())->speedVsLine();
    $mallet = stats()->forPutter(mallet())->speedVsLine();

    expect($blade['speed'])->toBe(10)
        ->and($blade['line'])->toBe(0)
        ->and($mallet['speed'])->toBe(0)
        ->and($mallet['line'])->toBe(10);
});

it('names the putter in its insights when scoped', function () {
    logPutts(40, PuttResult::MissShort, 15, PuttContext::Inside, mallet());

    $insights = stats()->forPutter(mallet())->insights();

    expect(implode(' ', $insights))->toContain('With Mallet');
});

it('scopes stats to a single session', function () {
    $tonight = PuttingSession::factory()->for(blade())->create();
    $lastWeek = PuttingSession::factory()->for(blade())->create();

    Putt::factory()->count(10)->create([
        'putting_session_id' => $tonight->id,
        'result' => PuttResult::Sunk,
        'distance_ft' => 10,
    ]);

    Putt::factory()->count(30)->create([
        'putting_session_id' => $lastWeek->id,
        'result' => PuttResult::MissShort,
        'distance_ft' => 10,
    ]);

    $scoped = stats()->forSession($tonight);

    expect($scoped->byDistance()->sum('attempts'))->toBe(10)
        ->and($scoped->missDial()['sunk']['percent'])->toBe(100.0)
        ->and(stats()->byDistance()->sum('attempts'))->toBe(40);
});

it('reads a session that holds the other putter', function () {
    $malletNight = PuttingSession::factory()->for(mallet())->create();

    Putt::factory()->count(8)->create([
        'putting_session_id' => $malletNight->id,
        'result' => PuttResult::Sunk,
        'distance_ft' => 12,
    ]);

    logPutts(50, PuttResult::MissShort, 12, PuttContext::Inside, blade());

    $scoped = stats()->forSession($malletNight);

    expect($scoped->byDistance()->sum('attempts'))->toBe(8)
        ->and($scoped->missDial()['sunk']['percent'])->toBe(100.0);
});

it('scopes stats to inside or outside', function () {
    logPutts(20, PuttResult::Sunk, 10, PuttContext::Inside, blade());
    logPutts(20, PuttResult::MissShort, 10, PuttContext::Outside, blade());

    $stats = stats()->forPutter(blade());

    expect($stats->inContext(PuttContext::Inside)->missDial()['sunk']['percent'])->toBe(100.0)
        ->and($stats->inContext(PuttContext::Outside)->missDial()['sunk']['percent'])->toBe(0.0)
        ->and($stats->inContext(null)->missDial()['sunk']['percent'])->toBe(50.0);
});

it('combines putter and context scopes', function () {
    logPutts(10, PuttResult::Sunk, 10, PuttContext::Outside, mallet());
    logPutts(30, PuttResult::MissShort, 10, PuttContext::Outside, blade());
    logPutts(30, PuttResult::MissLong, 10, PuttContext::Inside, mallet());

    $scoped = stats()->forPutter(mallet())->inContext(PuttContext::Outside);

    expect($scoped->byDistance()->sum('attempts'))->toBe(10)
        ->and($scoped->missDial()['sunk']['percent'])->toBe(100.0);
});

it('names both the putter and the context in its insights', function () {
    logPutts(40, PuttResult::MissShort, 15, PuttContext::Outside, mallet());

    $insights = stats()
        ->forPutter(mallet())
        ->inContext(PuttContext::Outside)
        ->insights();

    expect(implode(' ', $insights))->toContain('With Mallet outside');
});

it('has nothing to compare inside against outside once a context is pinned', function () {
    logPutts(10, PuttResult::Sunk, 10, PuttContext::Inside, blade());
    logPutts(10, PuttResult::MissShort, 10, PuttContext::Outside, blade());

    $stats = stats()->forPutter(blade());

    expect($stats->inContext(null)->insideVsOutside())->toHaveCount(1)
        ->and($stats->inContext(PuttContext::Outside)->insideVsOutside())->toBeEmpty();
});

it('scopes stats to one position around the hole', function () {
    logMakeRate(40, 80, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::Below);
    logMakeRate(40, 20, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::AboveRight);

    $stats = stats();

    expect($stats->atPosition(ClockPosition::Below)->byDistance()->first()['make_percent'])->toBe(80.0)
        ->and($stats->atPosition(ClockPosition::AboveRight)->byDistance()->first()['make_percent'])->toBe(20.0);
});

it('scopes stats by slope, which reaches the coarser rollup', function () {
    logMakeRate(40, 80, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::Below);
    logMakeRate(40, 70, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::BelowLeft);
    logMakeRate(40, 20, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::AboveRight);

    $stats = stats();

    // Uphill pools the two below-the-hole positions into one sample.
    expect($stats->onSlope(PuttSlope::Uphill)->byDistance()->first()['attempts'])->toBe(80)
        ->and($stats->onSlope(PuttSlope::Downhill)->byDistance()->first()['attempts'])->toBe(40);
});

it('composes the position scope with putter and context', function () {
    logMakeRate(40, 90, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::Below);
    logMakeRate(40, 10, 10, mallet(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::Below);
    logMakeRate(40, 50, 10, blade(), PuttContext::Inside, PuttResult::MissShort, ClockPosition::Below);

    $scoped = stats()
        ->forPutter(blade())
        ->inContext(PuttContext::Outside)
        ->atPosition(ClockPosition::Below);

    expect($scoped->byDistance()->first()['make_percent'])->toBe(90.0)
        ->and($scoped->byDistance()->first()['attempts'])->toBe(40);
});

it('moves the fifty percent distance when the slope changes', function () {
    // Uphill holds 50% out past 10 ft; downhill drops through it before 10.
    foreach ([[5, 90.0], [10, 70.0], [20, 30.0]] as [$distance, $rate]) {
        logMakeRate(40, $rate, $distance, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::Below);
    }

    foreach ([[5, 60.0], [10, 30.0], [20, 10.0]] as [$distance, $rate]) {
        logMakeRate(40, $rate, $distance, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::AboveRight);
    }

    $uphill = stats()->onSlope(PuttSlope::Uphill)->fiftyPercentDistance();
    $downhill = stats()->onSlope(PuttSlope::Downhill)->fiftyPercentDistance();

    expect($uphill)->not->toBeNull()
        ->and($downhill)->not->toBeNull()
        ->and($uphill)->toBeGreaterThan($downhill);
});

it('counts putts logged before positions existed as unclassified, not as flat', function () {
    logPutts(30, PuttResult::Sunk, 10);
    logPutts(20, PuttResult::Sunk, 10, PuttContext::Inside, blade(), ClockPosition::Flat);

    $positions = stats()->byClockPosition();

    expect($positions['unclassified'])->toBe(30)
        ->and($positions['classified'])->toBe(20)
        ->and($positions['positions'][ClockPosition::Flat->value]['attempts'])->toBe(20);
});

it('pools mirrored positions into one band and splits them by break side', function () {
    logMakeRate(40, 30, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::AboveRight);
    logMakeRate(60, 20, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::AboveLeft);

    $positions = stats()->byClockPosition();

    expect($positions['bands']['downhill_sidehill']['attempts'])->toBe(100)
        ->and($positions['breaks'][BreakSide::RightToLeft->value]['attempts'])->toBe(40)
        ->and($positions['breaks'][BreakSide::LeftToRight->value]['attempts'])->toBe(60);
});

it('does not call a straight-versus-breaking gap a read problem', function () {
    // Straight putts are easier than breaking ones for everyone, and straight pools
    // the indoor mat. That is not a one-sided read bias and must not read as one.
    logMakeRate(40, 70, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::Below);
    logMakeRate(40, 30, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::AboveRight);

    $insights = implode(' ', stats()->insights());

    expect($insights)->not->toContain('aim or read, not speed');
});

it('blames the slope when both break directions hold up equally', function () {
    // Downhill is far worse than uphill, but the two break sides match.
    foreach ([ClockPosition::AboveRight, ClockPosition::AboveLeft] as $downhill) {
        logMakeRate(40, 15, 10, blade(), PuttContext::Outside, PuttResult::MissShort, $downhill);
    }

    foreach ([ClockPosition::BelowRight, ClockPosition::BelowLeft] as $uphill) {
        logMakeRate(40, 65, 10, blade(), PuttContext::Outside, PuttResult::MissShort, $uphill);
    }

    $insights = implode(' ', stats()->insights());

    expect($insights)->toContain('it is the slope beating you rather than the read');
});

it('blames the read when one break direction is worse at the same slope', function () {
    // Both sides of the clock, so slope pools out — but one break direction is worse.
    foreach ([ClockPosition::AboveRight, ClockPosition::BelowRight] as $rightToLeft) {
        logMakeRate(40, 60, 10, blade(), PuttContext::Outside, PuttResult::MissShort, $rightToLeft);
    }

    foreach ([ClockPosition::AboveLeft, ClockPosition::BelowLeft] as $leftToRight) {
        logMakeRate(40, 25, 10, blade(), PuttContext::Outside, PuttResult::MissShort, $leftToRight);
    }

    $insights = implode(' ', stats()->insights());

    expect($insights)->toContain('not a speed problem');
});
