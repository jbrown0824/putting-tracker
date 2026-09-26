<?php

use App\Actions\GenerateDemoPutts;
use App\Enums\PuttResult;
use App\Models\Putt;
use App\Models\PuttingSession;
use App\Models\User;

function countOf(PuttResult $result): int
{
    return Putt::query()->where('result', $result)->count();
}

it('generates putts and sessions', function () {
    $summary = app(GenerateDemoPutts::class)->execute(testUser(), days: 5, profileName: 'elite');

    expect(Putt::count())->toBe($summary['putts'])
        ->and($summary['putts'])->toBeGreaterThan(0)
        ->and(PuttingSession::count())->toBe($summary['sessions']);
});

it('replaces the previous dataset instead of appending', function () {
    $generate = app(GenerateDemoPutts::class);

    $first = $generate->execute(testUser(), days: 4, profileName: 'lag');
    $second = $generate->execute(testUser(), days: 4, profileName: 'lag', replaceExisting: true);

    expect(Putt::count())->toBe($second['putts'])
        ->and(Putt::count())->not->toBe($first['putts'] + $second['putts']);
});

it('refuses to delete existing putts unless asked to replace them', function () {
    $generate = app(GenerateDemoPutts::class);

    $generate->execute(testUser(), days: 4, profileName: 'lag');
    $before = Putt::count();

    expect(fn () => $generate->execute(testUser(), days: 4, profileName: 'lag'))
        ->toThrow(RuntimeException::class)
        ->and(Putt::count())->toBe($before);
});

it('gives the lag profile a short-miss bias', function () {
    app(GenerateDemoPutts::class)->execute(testUser(), days: 12, profileName: 'lag');

    expect(countOf(PuttResult::MissShort))->toBeGreaterThan(countOf(PuttResult::MissLong) * 2);
});

it('gives the charger profile the mirrored long-miss bias', function () {
    app(GenerateDemoPutts::class)->execute(testUser(), days: 12, profileName: 'charger');

    expect(countOf(PuttResult::MissLong))->toBeGreaterThan(countOf(PuttResult::MissShort) * 2);
});

it('gives the pusher profile a right-miss bias', function () {
    app(GenerateDemoPutts::class)->execute(testUser(), days: 12, profileName: 'pusher');

    expect(countOf(PuttResult::MissRight))->toBeGreaterThan(countOf(PuttResult::MissLeft) * 2);
});

it('rejects an unknown profile', function () {
    app(GenerateDemoPutts::class)->execute(testUser(), days: 2, profileName: 'banana');
})->throws(InvalidArgumentException::class);

it('only replaces the chosen player\'s putts', function () {
    testUser();
    $stranger = User::factory()->create();
    logPutts(5, PuttResult::Sunk, 10, putter: blade($stranger));

    app(GenerateDemoPutts::class)->execute(testUser(), days: 3, profileName: 'elite');
    app(GenerateDemoPutts::class)->execute(testUser(), days: 3, profileName: 'elite', replaceExisting: true);

    expect($stranger->putts()->count())->toBe(5)
        ->and(testUser()->putters()->pluck('name')->sort()->values()->all())->toBe(['Demo blade', 'Demo mallet']);
});

it('runs from the console command', function () {
    $this->artisan('putts:demo', ['email' => testUser()->email, '--days' => 3, '--profile' => 'elite'])
        ->assertSuccessful();

    expect(Putt::count())->toBeGreaterThan(0);
});

it('fails the console command on a bad profile', function () {
    $this->artisan('putts:demo', ['email' => testUser()->email, '--profile' => 'banana'])->assertFailed();
});

it('fails the console command for an unknown player', function () {
    $this->artisan('putts:demo', ['email' => 'nobody@example.com'])->assertFailed();
});
