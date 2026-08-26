<?php

use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Models\Challenge;
use App\Models\Putt;
use App\Models\PuttingSession;
use App\Services\PutterComparison;
use App\Services\PuttingProfile;
use App\Services\PuttStats;
use Illuminate\Support\Carbon;

function logCause(int $count, PuttResult $result, ?LineMissCause $cause, PuttContext $context = PuttContext::Inside, Putter $putter = Putter::Blade): void
{
    $session = PuttingSession::factory()->create(['context' => $context, 'putter' => $putter]);

    Putt::factory()->count($count)->create([
        'putting_session_id' => $session->id,
        'result' => $result,
        'miss_cause' => $cause,
        'distance_ft' => 10,
        'context' => $context,
        'putter' => $putter,
        'hit_at' => Carbon::now(),
    ]);
}

it('names the miss the way a golfer would', function () {
    expect(LineMissCause::Stroke->labelFor(PuttResult::MissLeft))->toBe('Pull')
        ->and(LineMissCause::Stroke->labelFor(PuttResult::MissRight))->toBe('Push')
        ->and(LineMissCause::Read->labelFor(PuttResult::MissLeft))->toBe('Misread');
});

it('only applies to left and right misses', function () {
    expect(LineMissCause::appliesTo(PuttResult::MissLeft))->toBeTrue()
        ->and(LineMissCause::appliesTo(PuttResult::MissRight))->toBeTrue()
        ->and(LineMissCause::appliesTo(PuttResult::Sunk))->toBeFalse()
        ->and(LineMissCause::appliesTo(PuttResult::MissShort))->toBeFalse();
});

it('splits line misses by cause and keeps unclassified ones separate', function () {
    logCause(30, PuttResult::MissLeft, LineMissCause::Stroke);
    logCause(10, PuttResult::MissRight, LineMissCause::Read);
    logCause(20, PuttResult::MissLeft, null);
    logCause(40, PuttResult::MissShort, null);

    $causes = app(PuttStats::class)->lineMissCauses();

    expect($causes['stroke'])->toBe(30)
        ->and($causes['read'])->toBe(10)
        ->and($causes['classified'])->toBe(40)
        ->and($causes['unclassified'])->toBe(20)
        ->and($causes['stroke_percent'])->toBe(75.0)
        ->and($causes['read_percent'])->toBe(25.0);
});

it('reports the cause split separately for inside and outside', function () {
    logCause(18, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Inside);
    logCause(2, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Inside);
    logCause(5, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Outside);
    logCause(15, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Outside);

    $byContext = app(PuttStats::class)->lineMissCauses()['by_context'];

    expect($byContext['inside']['read_percent'])->toBe(10.0)
        ->and($byContext['outside']['read_percent'])->toBe(75.0);
});

it('scopes the cause split to the putter', function () {
    logCause(20, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Inside, Putter::Blade);
    logCause(20, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Inside, Putter::Mallet);

    $blade = app(PuttStats::class)->forPutter(Putter::Blade)->lineMissCauses();
    $mallet = app(PuttStats::class)->forPutter(Putter::Mallet)->lineMissCauses();

    expect($blade['stroke_percent'])->toBe(100.0)
        ->and($mallet['read_percent'])->toBe(100.0);
});

it('splits the radar line axis in two once enough misses carry a cause', function () {
    logPutts(60, PuttResult::Sunk, 10);
    logCause(9, PuttResult::MissLeft, LineMissCause::Stroke);

    $profile = app(PuttingProfile::class);

    expect(array_column($profile->build(app(PuttStats::class)), 'key'))
        ->toBe(['short', 'mid', 'lag', 'speed', 'line']);

    logCause(6, PuttResult::MissRight, LineMissCause::Read);

    expect(array_column($profile->build(app(PuttStats::class)), 'key'))
        ->toBe(['short', 'mid', 'lag', 'speed', 'stroke', 'read']);
});

it('extrapolates the classified sample across every line miss on the radar', function () {
    logPutts(80, PuttResult::Sunk, 10);
    // 20 line misses, half classified and all of those strokes.
    logCause(10, PuttResult::MissLeft, LineMissCause::Stroke);
    logCause(10, PuttResult::MissLeft, null);

    $axes = app(PuttingProfile::class)->build(app(PuttStats::class));
    $stroke = collect($axes)->firstWhere('key', 'stroke');

    // All 20 count as strokes, not just the 10 that were labelled: 20 of 100 putts.
    expect($stroke['value'])->toBe(20.0);
});

it('credits the putter that squares the face rather than the one that reads greens', function () {
    // Same line-miss count each; the mallet's are mostly misreads, so its stroke is better.
    logPutts(180, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Blade);
    logCause(36, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Inside, Putter::Blade);
    logCause(4, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Inside, Putter::Blade);

    logPutts(180, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Mallet);
    logCause(8, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Inside, Putter::Mallet);
    logCause(32, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Inside, Putter::Mallet);

    $strengths = app(PutterComparison::class)->strengths();

    expect(array_column($strengths[Putter::Mallet->value], 'headline'))->toContain('Squares the face')
        ->and(array_column($strengths[Putter::Blade->value], 'headline'))->not->toContain('Squares the face');
});

it('calls out whether real greens expose the read', function () {
    logPutts(40, PuttResult::Sunk, 10, PuttContext::Inside);
    logCause(18, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Inside);
    logCause(2, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Inside);
    logCause(3, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Outside);
    logCause(15, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Outside);

    expect(implode(' ', app(PuttStats::class)->insights()))
        ->toContain('exposing the read');
});

it('drops a cause sent on a result that cannot have one', function () {
    Challenge::factory()->create();

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['result' => 'sunk', 'miss_cause' => 'read'])],
    ])->assertOk();

    expect(Putt::first()->miss_cause)->toBeNull();
});

it('stores a cause on a line miss and rejects an unknown one', function () {
    Challenge::factory()->create();

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['result' => 'miss_left', 'miss_cause' => 'stroke'])],
    ])->assertOk();

    expect(Putt::first()->miss_cause)->toBe(LineMissCause::Stroke);

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['result' => 'miss_left', 'miss_cause' => 'yips'])],
    ])->assertJsonValidationErrorFor('putts.0.miss_cause');
});

it('still accepts a putt with no cause at all', function () {
    Challenge::factory()->create();

    $this->postJson(route('api.putts.sync'), [
        'putts' => [puttPayload(['result' => 'miss_left'])],
    ])->assertOk();

    expect(Putt::first()->miss_cause)->toBeNull();
});

it('keeps both compared putters on the same radar axes', function () {
    // The blade clears the classification threshold; the mallet does not. Overlaying
    // a hexagon on a pentagon would misalign every axis, so neither may split.
    logPutts(60, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Blade);
    logCause(20, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Inside, Putter::Blade);

    logPutts(60, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Mallet);
    logCause(3, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Inside, Putter::Mallet);

    $profiles = app(PutterComparison::class)->profiles();

    $bladeKeys = array_column($profiles[Putter::Blade->value], 'key');
    $malletKeys = array_column($profiles[Putter::Mallet->value], 'key');

    expect($bladeKeys)->toBe($malletKeys)
        ->and($bladeKeys)->toBe(['short', 'mid', 'lag', 'speed', 'line']);
});

it('splits both radar shapes once both putters qualify', function () {
    foreach ([Putter::Blade, Putter::Mallet] as $putter) {
        logPutts(60, PuttResult::Sunk, 10, PuttContext::Inside, $putter);
        logCause(12, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Inside, $putter);
    }

    $profiles = app(PutterComparison::class)->profiles();

    expect(array_column($profiles[Putter::Blade->value], 'key'))
        ->toBe(['short', 'mid', 'lag', 'speed', 'stroke', 'read'])
        ->and(array_column($profiles[Putter::Mallet->value], 'key'))
        ->toBe(['short', 'mid', 'lag', 'speed', 'stroke', 'read']);
});

it('renders the compare page when only one putter has classified misses', function () {
    logPutts(60, PuttResult::Sunk, 10, PuttContext::Outside, Putter::Blade);
    logCause(20, PuttResult::MissLeft, LineMissCause::Stroke, PuttContext::Outside, Putter::Blade);

    logPutts(60, PuttResult::Sunk, 10, PuttContext::Outside, Putter::Mallet);
    logCause(4, PuttResult::MissLeft, LineMissCause::Read, PuttContext::Outside, Putter::Mallet);

    $this->get(route('stats.compare', ['context' => 'outside']))
        ->assertOk()
        ->assertSee('Play style');
});
