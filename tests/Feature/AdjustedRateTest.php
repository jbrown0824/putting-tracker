<?php

use App\Enums\ClockPosition;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Services\AdjustedRate;
use App\Services\PutterComparison;
use App\Services\PuttStats;

/**
 * The case the whole service exists for. The mallet is genuinely the worse putter at
 * every single distance, but it has been used mostly on tap-ins, so its raw make rate
 * comes out higher. Levelling the mix has to undo that.
 */
it('strips out a mix advantage that the raw rate hands over', function () {
    // Blade: an even spread, and better everywhere.
    logMakeRate(100, 80, 3, Putter::Blade);
    logMakeRate(100, 50, 10, Putter::Blade);
    logMakeRate(100, 20, 20, Putter::Blade);

    // Mallet: worse at every distance, but 80% of its putts are the easy ones.
    logMakeRate(240, 70, 3, Putter::Mallet);
    logMakeRate(30, 40, 10, Putter::Mallet);
    logMakeRate(30, 10, 20, Putter::Mallet);

    $stats = app(PuttStats::class);
    $matched = app(AdjustedRate::class)->matched([
        'blade' => $stats->forPutter(Putter::Blade),
        'mallet' => $stats->forPutter(Putter::Mallet),
    ]);

    $blade = $matched['scopes']['blade'];
    $mallet = $matched['scopes']['mallet'];

    // The raw numbers say the mallet is better.
    expect($mallet['raw_percent'])->toBeGreaterThan($blade['raw_percent']);

    // Levelled, the truth comes out.
    expect($mallet['adjusted_percent'])->toBeLessThan($blade['adjusted_percent'])
        ->and($mallet['delta'])->toBeLessThan(0);
});

it('flips the putter verdict once the mix is levelled', function () {
    logMakeRate(150, 80, 3, Putter::Blade);
    logMakeRate(150, 50, 10, Putter::Blade);
    logMakeRate(150, 25, 20, Putter::Blade);

    logMakeRate(320, 66, 3, Putter::Mallet);
    logMakeRate(70, 32, 10, Putter::Mallet);
    logMakeRate(70, 12, 20, Putter::Mallet);

    $verdict = app(PutterComparison::class)->verdict();

    expect($verdict['state'])->toBe('recommended')
        ->and($verdict['putter'])->toBe(Putter::Blade);
});

it('leaves an honest mix alone', function () {
    logMakeRate(100, 70, 3, Putter::Blade);
    logMakeRate(100, 40, 10, Putter::Blade);
    logMakeRate(100, 70, 3, Putter::Mallet);
    logMakeRate(100, 40, 10, Putter::Mallet);

    $stats = app(PuttStats::class);
    $matched = app(AdjustedRate::class)->matched([
        'blade' => $stats->forPutter(Putter::Blade),
        'mallet' => $stats->forPutter(Putter::Mallet),
    ]);

    expect(abs($matched['scopes']['blade']['delta']))->toBeLessThanOrEqual(1.0)
        ->and(abs($matched['scopes']['mallet']['delta']))->toBeLessThanOrEqual(1.0);
});

it('matches on context as well as distance', function () {
    // Same distance, but the blade played it indoors and the mallet outdoors.
    logMakeRate(100, 60, 10, Putter::Blade, PuttContext::Inside);
    logMakeRate(100, 60, 10, Putter::Mallet, PuttContext::Outside);

    $stats = app(PuttStats::class);
    $matched = app(AdjustedRate::class)->matched([
        'blade' => $stats->forPutter(Putter::Blade),
        'mallet' => $stats->forPutter(Putter::Mallet),
    ]);

    // Nothing overlaps once context is part of the stratum, so there is no shared
    // sample to compare on — which is the right answer, not a rate of 60% each.
    expect($matched['dimensions'])->toContain('context')
        ->and($matched['sample'])->toBe(0)
        ->and($matched['scopes']['blade']['adjusted_percent'])->toBeNull();
});

it('ignores cells too thin to mean anything', function () {
    logMakeRate(100, 50, 10, Putter::Blade);
    logMakeRate(100, 50, 10, Putter::Mallet);

    // Three putts at 30ft is not a distance either putter has really played.
    logPutts(3, PuttResult::Sunk, 30, PuttContext::Inside, Putter::Blade);
    logPutts(3, PuttResult::MissShort, 30, PuttContext::Inside, Putter::Mallet);

    $stats = app(PuttStats::class);
    $matched = app(AdjustedRate::class)->matched([
        'blade' => $stats->forPutter(Putter::Blade),
        'mallet' => $stats->forPutter(Putter::Mallet),
    ]);

    expect($matched['cells'])->toBe(1)
        ->and($matched['sample'])->toBe(100);
});

it('holds slope out of the strata until enough putts carry a position', function () {
    logMakeRate(100, 50, 10, Putter::Blade);
    logMakeRate(100, 50, 10, Putter::Mallet);

    $stats = app(PuttStats::class);
    $adjusted = app(AdjustedRate::class);

    expect($adjusted->matched([
        'blade' => $stats->forPutter(Putter::Blade),
        'mallet' => $stats->forPutter(Putter::Mallet),
    ])['dimensions'])->not->toContain('slope');

    // Once both putters have enough tagged putts, the dimension engages.
    logMakeRate(80, 50, 10, Putter::Blade, PuttContext::Inside, PuttResult::MissShort, ClockPosition::Above);
    logMakeRate(80, 50, 10, Putter::Mallet, PuttContext::Inside, PuttResult::MissShort, ClockPosition::Above);

    expect($adjusted->matched([
        'blade' => $stats->forPutter(Putter::Blade),
        'mallet' => $stats->forPutter(Putter::Mallet),
    ])['dimensions'])->toContain('slope');
});

it('standardises one scope against the whole dataset rather than against itself', function () {
    logMakeRate(200, 80, 3, Putter::Blade);
    logMakeRate(40, 30, 20, Putter::Blade);
    logMakeRate(40, 80, 3, Putter::Mallet);
    logMakeRate(200, 30, 20, Putter::Mallet);

    $stats = app(PuttStats::class);
    $blade = app(AdjustedRate::class)->standardised($stats->forPutter(Putter::Blade), $stats);

    // The blade's own mix is far easier than the pooled one, so levelling has to
    // pull its number down rather than leave it where it was.
    expect($blade['reliable'])->toBeTrue()
        ->and($blade['adjusted_percent'])->toBeLessThan($blade['raw_percent'])
        ->and($blade['note'])->toContain('flatters you');
});

it('refuses to adjust when the scope barely overlaps the reference mix', function () {
    // The reference is dominated by putts the scope has never taken.
    logMakeRate(400, 30, 20, Putter::Mallet);
    logMakeRate(30, 80, 3, Putter::Blade);

    $stats = app(PuttStats::class);
    $blade = app(AdjustedRate::class)->standardised($stats->forPutter(Putter::Blade), $stats);

    expect($blade['reliable'])->toBeFalse()
        ->and($blade['adjusted_percent'])->toBeNull()
        ->and($blade['raw_percent'])->toBeGreaterThan(0);
});
