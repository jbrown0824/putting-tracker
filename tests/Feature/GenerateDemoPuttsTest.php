<?php

use App\Actions\GenerateDemoPutts;
use App\Enums\PuttResult;
use App\Models\Challenge;
use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Support\Carbon;

function countOf(PuttResult $result): int
{
    return Putt::query()->where('result', $result)->count();
}

it('generates putts and sessions', function () {
    Challenge::factory()->create();

    $summary = app(GenerateDemoPutts::class)->execute(days: 5, profileName: 'elite');

    expect(Putt::count())->toBe($summary['putts'])
        ->and($summary['putts'])->toBeGreaterThan(0)
        ->and(PuttingSession::count())->toBe($summary['sessions']);
});

it('replaces the previous dataset instead of appending', function () {
    Challenge::factory()->create();
    $generate = app(GenerateDemoPutts::class);

    $first = $generate->execute(days: 4, profileName: 'lag');
    $second = $generate->execute(days: 4, profileName: 'lag', replaceExisting: true);

    expect(Putt::count())->toBe($second['putts'])
        ->and(Putt::count())->not->toBe($first['putts'] + $second['putts']);
});

it('refuses to delete existing putts unless asked to replace them', function () {
    Challenge::factory()->create();
    $generate = app(GenerateDemoPutts::class);

    $generate->execute(days: 4, profileName: 'lag');
    $before = Putt::count();

    expect(fn () => $generate->execute(days: 4, profileName: 'lag'))
        ->toThrow(RuntimeException::class)
        ->and(Putt::count())->toBe($before);
});

it('gives the lag profile a short-miss bias', function () {
    Challenge::factory()->create();

    app(GenerateDemoPutts::class)->execute(days: 12, profileName: 'lag');

    expect(countOf(PuttResult::MissShort))->toBeGreaterThan(countOf(PuttResult::MissLong) * 2);
});

it('gives the charger profile the mirrored long-miss bias', function () {
    Challenge::factory()->create();

    app(GenerateDemoPutts::class)->execute(days: 12, profileName: 'charger');

    expect(countOf(PuttResult::MissLong))->toBeGreaterThan(countOf(PuttResult::MissShort) * 2);
});

it('gives the pusher profile a right-miss bias', function () {
    Challenge::factory()->create();

    app(GenerateDemoPutts::class)->execute(days: 12, profileName: 'pusher');

    expect(countOf(PuttResult::MissRight))->toBeGreaterThan(countOf(PuttResult::MissLeft) * 2);
});

it('rejects an unknown profile', function () {
    app(GenerateDemoPutts::class)->execute(days: 2, profileName: 'banana');
})->throws(InvalidArgumentException::class);

it('widens the challenge window back to cover the generated days', function () {
    Carbon::setTestNow('2026-08-23 12:00:00');
    $challenge = Challenge::factory()->create(['start_date' => '2026-08-23']);

    app(GenerateDemoPutts::class)->execute(days: 10);

    expect($challenge->fresh()->start_date->toDateString())->toBe('2026-08-14');

    Carbon::setTestNow();
});

it('leaves the challenge window alone when asked', function () {
    Carbon::setTestNow('2026-08-23 12:00:00');
    $challenge = Challenge::factory()->create(['start_date' => '2026-08-23']);

    app(GenerateDemoPutts::class)->execute(days: 10, alignChallengeWindow: false);

    expect($challenge->fresh()->start_date->toDateString())->toBe('2026-08-23');

    Carbon::setTestNow();
});

it('runs from the console command', function () {
    Challenge::factory()->create();

    $this->artisan('putts:demo', ['--days' => 3, '--profile' => 'elite'])
        ->assertSuccessful();

    expect(Putt::count())->toBeGreaterThan(0);
});

it('fails the console command on a bad profile', function () {
    $this->artisan('putts:demo', ['--profile' => 'banana'])->assertFailed();
});
