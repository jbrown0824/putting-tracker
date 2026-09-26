<?php

use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Services\PuttingProfile;

function axis(array $axes, string $key): array
{
    return collect($axes)->firstWhere('key', $key);
}

it('scores an axis at the baseline when you match the reference curve', function () {
    $profile = app(PuttingProfile::class);

    logMakeRate(300, $profile->referenceMakeRate(4), 4, blade());

    expect(axis($profile->build(stats()), 'short')['score'])
        ->toBe(PuttingProfile::BASELINE_SCORE);
});

it('scores full marks at double the reference curve', function () {
    $profile = app(PuttingProfile::class);

    logMakeRate(300, $profile->referenceMakeRate(10), 10, blade());
    logMakeRate(300, $profile->referenceMakeRate(10) * 2, 10, mallet());

    $weak = $profile->build(stats()->forPutter(blade()));
    $strong = $profile->build(stats()->forPutter(mallet()));

    expect(axis($weak, 'mid')['score'])->toBe(PuttingProfile::BASELINE_SCORE)
        ->and(axis($strong, 'mid')['score'])->toBe(100);
});

it('falls away with distance the way real make rates do', function () {
    $profile = app(PuttingProfile::class);

    // Steep up close, flattening out long — not a straight line.
    expect(round($profile->referenceMakeRate(3)))->toBeGreaterThan(85.0)
        ->and(round($profile->referenceMakeRate(7.5)))->toBe(50.0)
        ->and(round($profile->referenceMakeRate(15)))->toBeLessThan(25.0)
        ->and(round($profile->referenceMakeRate(30)))->toBeLessThan(10.0);
});

it('does not let the distance mix inside a band drive the score', function () {
    $profile = app(PuttingProfile::class);

    // Both play the reference exactly, but one lags from 15ft and the other from 30.
    logMakeRate(300, $profile->referenceMakeRate(15), 15, blade());
    logMakeRate(300, $profile->referenceMakeRate(30), 30, mallet());

    $near = axis($profile->build(stats()->forPutter(blade())), 'lag');
    $far = axis($profile->build(stats()->forPutter(mallet())), 'lag');

    // Pooled make rates differ hugely; the scores should barely move.
    expect($near['value'])->toBeGreaterThan($far['value'] + 10)
        ->and(abs($near['score'] - $far['score']))->toBeLessThanOrEqual(3);
});

it('leaves an axis unscored until it has enough attempts', function () {
    logMakeRate(60, 50, 10, blade());
    logMakeRate(4, 50, 3, blade());

    $axes = app(PuttingProfile::class)->build(stats());

    expect(axis($axes, 'short')['score'])->toBeNull()
        ->and(axis($axes, 'short')['attempts'])->toBe(4)
        ->and(axis($axes, 'mid')['score'])->not->toBeNull();
});

it('scores the error axes upside down, so fewer misses is better', function () {
    logPutts(70, PuttResult::Sunk, 10);
    logPutts(30, PuttResult::MissShort, 10);

    $speedHeavy = app(PuttingProfile::class)->build(stats());

    logPutts(200, PuttResult::MissLeft, 10, PuttContext::Inside, mallet());
    $lineHeavy = app(PuttingProfile::class)->build(stats()->forPutter(mallet()));

    expect(axis($speedHeavy, 'speed')['score'])->toBe(PuttingProfile::BASELINE_SCORE)
        ->and(axis($speedHeavy, 'line')['score'])->toBe(100)
        ->and(axis($lineHeavy, 'line')['score'])->toBeLessThan(PuttingProfile::BASELINE_SCORE);
});

it('separates a speed problem from a line problem in the shape', function () {
    logPutts(50, PuttResult::Sunk, 10, PuttContext::Inside, blade());
    logPutts(50, PuttResult::MissShort, 10, PuttContext::Inside, blade());

    logPutts(50, PuttResult::Sunk, 10, PuttContext::Inside, mallet());
    logPutts(50, PuttResult::MissLeft, 10, PuttContext::Inside, mallet());

    $blade = app(PuttingProfile::class)->build(stats()->forPutter(blade()));
    $mallet = app(PuttingProfile::class)->build(stats()->forPutter(mallet()));

    expect(axis($blade, 'speed')['score'])->toBeLessThan(axis($blade, 'line')['score'])
        ->and(axis($mallet, 'line')['score'])->toBeLessThan(axis($mallet, 'speed')['score']);
});

it('builds five axes in a stable order', function () {
    logMakeRate(60, 50, 10, blade());

    $axes = app(PuttingProfile::class)->build(stats());

    expect(array_column($axes, 'key'))->toBe(['short', 'mid', 'lag', 'speed', 'line']);
});

it('needs three scored axes before the chart is worth drawing', function () {
    $profile = app(PuttingProfile::class);

    logMakeRate(4, 50, 3, blade());
    expect($profile->isReadable($profile->build(stats())))->toBeFalse();

    logMakeRate(40, 50, 10, blade());
    logMakeRate(40, 50, 20, blade());
    expect($profile->isReadable($profile->build(stats())))->toBeTrue();
});

it('names the strongest and weakest axis', function () {
    // A realistic mix: misses split across both error types, so neither error axis
    // maxes out and outranks the distance bands by default.
    logPutts(57, PuttResult::Sunk, 4);
    logPutts(3, PuttResult::MissShort, 4);

    logPutts(24, PuttResult::Sunk, 10);
    logPutts(36, PuttResult::MissLeft, 10);

    logPutts(3, PuttResult::Sunk, 20);
    logPutts(57, PuttResult::MissShort, 20);

    $profile = app(PuttingProfile::class);
    $extremes = $profile->extremes($profile->build(stats()));

    expect($extremes['best']['key'])->toBe('short')
        ->and($extremes['worst']['key'])->toBe('lag');
});

it('respects the putter and context scopes it is handed', function () {
    logMakeRate(60, 90, 4, blade(), PuttContext::Inside);
    logMakeRate(60, 20, 4, blade(), PuttContext::Outside);

    $inside = app(PuttingProfile::class)->build(
        stats()->forPutter(blade())->inContext(PuttContext::Inside),
    );
    $outside = app(PuttingProfile::class)->build(
        stats()->forPutter(blade())->inContext(PuttContext::Outside),
    );

    expect(axis($inside, 'short')['score'])->toBeGreaterThan(axis($outside, 'short')['score'])
        ->and(axis($inside, 'short')['attempts'])->toBe(60);
});
