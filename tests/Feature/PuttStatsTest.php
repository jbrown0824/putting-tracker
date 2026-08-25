<?php

use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Models\Challenge;
use App\Models\Putt;
use App\Models\PuttingSession;
use App\Services\PuttStats;
use Illuminate\Support\Carbon;

it('reports progress and the pace needed to finish', function () {
    Carbon::setTestNow('2026-08-23 12:00:00');

    $challenge = Challenge::factory()->create([
        'start_date' => '2026-08-23',
        'end_date' => '2026-09-19',
        'target_total' => 2000,
        'target_outside_min' => 400,
    ]);

    logPutts(60, PuttResult::Sunk, 5);
    logPutts(40, PuttResult::MissShort, 5, PuttContext::Outside);

    $progress = app(PuttStats::class)->progress($challenge);

    expect($progress['total'])->toBe(100)
        ->and($progress['outside'])->toBe(40)
        ->and($progress['inside'])->toBe(60)
        ->and($progress['sunk'])->toBe(60)
        ->and($progress['make_percent'])->toBe(60.0)
        ->and($progress['remaining'])->toBe(1900)
        ->and($progress['days_remaining'])->toBe(28)
        ->and($progress['per_day_needed'])->toBe(68);

    Carbon::setTestNow();
});

it('counts 28 days for the challenge window', function () {
    $challenge = Challenge::factory()->create([
        'start_date' => '2026-08-23',
        'end_date' => '2026-09-19',
    ]);

    expect($challenge->totalDays())->toBe(28);
});

it('splits misses into speed and line errors', function () {
    logPutts(30, PuttResult::MissShort, 10);
    logPutts(10, PuttResult::MissLong, 10);
    logPutts(10, PuttResult::MissLeft, 10);
    logPutts(10, PuttResult::MissRight, 10);
    logPutts(5, PuttResult::LipOut, 10);
    logPutts(20, PuttResult::Sunk, 10);

    $split = app(PuttStats::class)->speedVsLine();

    expect($split['speed'])->toBe(40)
        ->and($split['line'])->toBe(20)
        ->and($split['lip_out'])->toBe(5)
        ->and($split['speed_percent'])->toBe(66.7);
});

it('builds the miss dial as shares of all putts', function () {
    logPutts(50, PuttResult::Sunk, 5);
    logPutts(50, PuttResult::MissShort, 5);

    $dial = app(PuttStats::class)->missDial();

    expect($dial['sunk']['percent'])->toBe(50.0)
        ->and($dial['miss_short']['percent'])->toBe(50.0)
        ->and($dial['miss_long']['count'])->toBe(0);
});

it('reports make rate and speed bias per distance', function () {
    logPutts(8, PuttResult::Sunk, 5);
    logPutts(2, PuttResult::MissShort, 5);
    logPutts(2, PuttResult::Sunk, 20);
    logPutts(8, PuttResult::MissShort, 20);

    $rows = app(PuttStats::class)->byDistance()->keyBy('distance_ft');

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

    expect(app(PuttStats::class)->fiftyPercentDistance())->toBe(15.0);
});

it('returns null for the fifty percent distance without data on both sides', function () {
    logPutts(10, PuttResult::Sunk, 5);

    expect(app(PuttStats::class)->fiftyPercentDistance())->toBeNull();
});

it('compares inside against outside at shared distances only', function () {
    logPutts(10, PuttResult::Sunk, 10, PuttContext::Inside);
    logPutts(5, PuttResult::Sunk, 10, PuttContext::Outside);
    logPutts(5, PuttResult::MissShort, 10, PuttContext::Outside);
    logPutts(10, PuttResult::Sunk, 30, PuttContext::Inside);

    $rows = app(PuttStats::class)->insideVsOutside();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['distance_ft'])->toBe(10)
        ->and($rows[0]['inside_percent'])->toBe(100.0)
        ->and($rows[0]['outside_percent'])->toBe(50.0)
        ->and($rows[0]['gap'])->toBe(50.0);
});

it('asks for more data before drawing conclusions', function () {
    logPutts(5, PuttResult::Sunk, 5);

    expect(app(PuttStats::class)->insights()[0])->toContain('25 putts');
});

it('calls out a dominant short miss tendency', function () {
    logPutts(40, PuttResult::MissShort, 15);
    logPutts(5, PuttResult::MissLong, 15);

    $insights = app(PuttStats::class)->insights();

    expect(implode(' ', $insights))->toContain('short');
});

it('tracks daily volume against the required pace', function () {
    Carbon::setTestNow('2026-08-24 12:00:00');

    $challenge = Challenge::factory()->create([
        'start_date' => '2026-08-23',
        'end_date' => '2026-09-19',
        'target_total' => 2000,
    ]);

    $session = PuttingSession::factory()->create();
    Putt::factory()->count(10)->create([
        'putting_session_id' => $session->id,
        'hit_at' => Carbon::parse('2026-08-23 09:00:00'),
    ]);

    $days = app(PuttStats::class)->dailyVolume($challenge);

    expect($days)->toHaveCount(28)
        ->and($days[0]['count'])->toBe(10)
        ->and($days[0]['cumulative'])->toBe(10)
        ->and($days[0]['target_cumulative'])->toBe(71)
        ->and($days[27]['cumulative'])->toBeNull();

    Carbon::setTestNow();
});

it('keeps each putter\'s performance stats completely isolated', function () {
    logPutts(20, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Blade);
    logPutts(20, PuttResult::MissShort, 10, PuttContext::Inside, Putter::Mallet);

    $blade = app(PuttStats::class)->forPutter(Putter::Blade);
    $mallet = app(PuttStats::class)->forPutter(Putter::Mallet);

    expect($blade->byDistance()->sum('attempts'))->toBe(20)
        ->and($blade->missDial()['sunk']['percent'])->toBe(100.0)
        ->and($mallet->byDistance()->sum('attempts'))->toBe(20)
        ->and($mallet->missDial()['sunk']['percent'])->toBe(0.0)
        ->and($mallet->missDial()['miss_short']['percent'])->toBe(100.0);
});

it('scopes the speed and line split to the selected putter', function () {
    logPutts(10, PuttResult::MissShort, 10, PuttContext::Inside, Putter::Blade);
    logPutts(10, PuttResult::MissLeft, 10, PuttContext::Inside, Putter::Mallet);

    $blade = app(PuttStats::class)->forPutter(Putter::Blade)->speedVsLine();
    $mallet = app(PuttStats::class)->forPutter(Putter::Mallet)->speedVsLine();

    expect($blade['speed'])->toBe(10)
        ->and($blade['line'])->toBe(0)
        ->and($mallet['speed'])->toBe(0)
        ->and($mallet['line'])->toBe(10);
});

it('names the putter in its insights when scoped', function () {
    logPutts(40, PuttResult::MissShort, 15, PuttContext::Inside, Putter::Mallet);

    $insights = app(PuttStats::class)->forPutter(Putter::Mallet)->insights();

    expect(implode(' ', $insights))->toContain('With the mallet');
});

it('counts both putters towards challenge progress even when scoped', function () {
    $challenge = Challenge::factory()->create(['target_total' => 2000]);

    logPutts(30, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Blade);
    logPutts(20, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Mallet);

    $scoped = app(PuttStats::class)->forPutter(Putter::Blade);

    expect($scoped->progress($challenge)['total'])->toBe(50)
        ->and($scoped->byDistance()->sum('attempts'))->toBe(30);
});

it('counts both putters in the daily volume pace line', function () {
    Carbon::setTestNow('2026-08-24 12:00:00');

    $challenge = Challenge::factory()->create([
        'start_date' => '2026-08-24',
        'end_date' => '2026-09-20',
        'target_total' => 2000,
    ]);

    logPutts(6, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Blade);
    logPutts(4, PuttResult::Sunk, 10, PuttContext::Inside, Putter::Mallet);

    $days = app(PuttStats::class)->forPutter(Putter::Blade)->dailyVolume($challenge);

    expect($days[0]['count'])->toBe(10);

    Carbon::setTestNow();
});

it('scopes stats to a single session', function () {
    $tonight = PuttingSession::factory()->create();
    $lastWeek = PuttingSession::factory()->create();

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

    $scoped = app(PuttStats::class)->forSession($tonight);

    expect($scoped->byDistance()->sum('attempts'))->toBe(10)
        ->and($scoped->missDial()['sunk']['percent'])->toBe(100.0)
        ->and(app(PuttStats::class)->byDistance()->sum('attempts'))->toBe(40);
});

it('reads a session that holds the other putter', function () {
    $malletNight = PuttingSession::factory()->mallet()->create();

    Putt::factory()->count(8)->mallet()->create([
        'putting_session_id' => $malletNight->id,
        'result' => PuttResult::Sunk,
        'distance_ft' => 12,
    ]);

    logPutts(50, PuttResult::MissShort, 12, PuttContext::Inside, Putter::Blade);

    $scoped = app(PuttStats::class)->forSession($malletNight);

    expect($scoped->byDistance()->sum('attempts'))->toBe(8)
        ->and($scoped->missDial()['sunk']['percent'])->toBe(100.0);
});

it('leaves challenge progress alone when scoped to a session', function () {
    $challenge = Challenge::factory()->create(['target_total' => 2000]);
    $tonight = PuttingSession::factory()->create();

    Putt::factory()->count(10)->create(['putting_session_id' => $tonight->id]);
    logPutts(25, PuttResult::Sunk, 10);

    $scoped = app(PuttStats::class)->forSession($tonight);

    expect($scoped->progress($challenge)['total'])->toBe(35)
        ->and($scoped->byDistance()->sum('attempts'))->toBe(10);
});
