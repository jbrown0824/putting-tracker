<?php

use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Services\PutterComparison;

it('compares only distances both putters have played enough', function () {
    logMakeRate(100, 50, 10, Putter::Blade);
    logMakeRate(100, 60, 10, Putter::Mallet);

    // Blade only. Should never reach the matched set, however good it looks.
    logMakeRate(100, 95, 3, Putter::Blade);

    // Played by both, but too thin on the mallet side to trust.
    logMakeRate(20, 40, 25, Putter::Blade);
    logMakeRate(3, 100, 25, Putter::Mallet);

    $rows = app(PutterComparison::class)->byDistance();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['distance_ft'])->toBe(10)
        ->and($rows[0]['gap'])->toBe(10.0);
});

it('weights a matched distance by the smaller of the two attempt counts', function () {
    logMakeRate(100, 50, 10, Putter::Blade);
    logMakeRate(20, 50, 10, Putter::Mallet);

    $rows = app(PutterComparison::class)->byDistance();

    expect($rows[0]['weight'])->toBe(20)
        ->and($rows[0]['blade_attempts'])->toBe(100)
        ->and($rows[0]['mallet_attempts'])->toBe(20);
});

it('refuses to recommend before either putter has enough putts', function () {
    logMakeRate(60, 30, 10, Putter::Blade);
    logMakeRate(40, 90, 10, Putter::Mallet);

    $verdict = app(PutterComparison::class)->verdict();

    expect($verdict['state'])->toBe('insufficient_data')
        ->and($verdict['putter'])->toBeNull()
        ->and($verdict['needed'])->toBe(60)
        ->and($verdict['message'])->toContain('mallet');
});

it('refuses to recommend when the two putters never share distances', function () {
    logMakeRate(120, 40, 10, Putter::Blade);
    logMakeRate(120, 80, 25, Putter::Mallet);

    $verdict = app(PutterComparison::class)->verdict();

    expect($verdict['state'])->toBe('insufficient_data')
        ->and($verdict['message'])->toContain('overlap');
});

it('calls a small gap too close rather than recommending on noise', function () {
    logMakeRate(200, 50, 10, Putter::Blade);
    logMakeRate(200, 53, 10, Putter::Mallet);

    $verdict = app(PutterComparison::class)->verdict();

    expect($verdict['state'])->toBe('too_close')
        ->and($verdict['putter'])->toBeNull()
        ->and($verdict['gap'])->toBe(3.0)
        ->and($verdict['message'])->toContain('inside the noise');
});

it('recommends the putter that is clearly better at matched distances', function () {
    logMakeRate(100, 30, 10, Putter::Blade);
    logMakeRate(100, 70, 10, Putter::Mallet);

    $verdict = app(PutterComparison::class)->verdict();

    expect($verdict['state'])->toBe('recommended')
        ->and($verdict['putter'])->toBe(Putter::Mallet)
        ->and($verdict['gap'])->toBe(40.0)
        ->and($verdict['sample'])->toBe(100)
        ->and($verdict['message'])->toContain('Play the mallet');
});

it('does not let volume at unmatched distances swing the recommendation', function () {
    // Even scoring where they overlap.
    logMakeRate(120, 50, 10, Putter::Blade);
    logMakeRate(120, 50, 10, Putter::Mallet);

    // A pile of tap-ins the mallet never took. Raw make rate would crown the blade.
    logMakeRate(200, 100, 2, Putter::Blade);

    $comparison = app(PutterComparison::class);

    expect($comparison->headline()[Putter::Blade->value]['make_percent'])->toBeGreaterThan(80.0)
        ->and($comparison->verdict()['state'])->toBe('too_close');
});

it('surfaces a short range edge as a strength', function () {
    logMakeRate(100, 60, 5, Putter::Blade);
    logMakeRate(100, 85, 5, Putter::Mallet);

    $strengths = app(PutterComparison::class)->strengths();

    expect($strengths[Putter::Mallet->value])->not->toBeEmpty()
        ->and($strengths[Putter::Blade->value])->toBeEmpty();

    $headlines = array_column($strengths[Putter::Mallet->value], 'headline');

    expect($headlines)->toContain('Short range');
});

it('credits the putter whose misses stay on line', function () {
    logPutts(120, PuttResult::MissShort, 10, PuttContext::Inside, Putter::Mallet);
    logPutts(120, PuttResult::MissLeft, 10, PuttContext::Inside, Putter::Blade);

    $strengths = app(PutterComparison::class)->strengths();
    $headlines = array_column($strengths[Putter::Mallet->value], 'headline');

    expect($headlines)->toContain('Holds the line');
});

it('reports the totals for each putter side by side', function () {
    logMakeRate(100, 40, 10, Putter::Blade);
    logMakeRate(50, 80, 10, Putter::Mallet, PuttContext::Outside);

    $headline = app(PutterComparison::class)->headline();

    expect($headline[Putter::Blade->value]['attempts'])->toBe(100)
        ->and($headline[Putter::Blade->value]['make_percent'])->toBe(40.0)
        ->and($headline[Putter::Blade->value]['inside']['attempts'])->toBe(100)
        ->and($headline[Putter::Blade->value]['outside']['attempts'])->toBe(0)
        ->and($headline[Putter::Mallet->value]['attempts'])->toBe(50)
        ->and($headline[Putter::Mallet->value]['outside']['make_percent'])->toBe(80.0);
});
