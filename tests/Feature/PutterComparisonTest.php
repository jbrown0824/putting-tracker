<?php

use App\Enums\PuttContext;
use App\Enums\PuttResult;

it('compares only distances both putters have played enough', function () {
    logMakeRate(100, 50, 10, blade());
    logMakeRate(100, 60, 10, mallet());

    // Blade only. Should never reach the matched set, however good it looks.
    logMakeRate(100, 95, 3, blade());

    // Played by both, but too thin on the mallet side to trust.
    logMakeRate(20, 40, 25, blade());
    logMakeRate(3, 100, 25, mallet());

    $rows = comparison()->byDistance();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['distance_ft'])->toBe(10)
        ->and($rows[0]['gap'])->toBe(10.0);
});

it('weights a matched distance by the smaller of the two attempt counts', function () {
    logMakeRate(100, 50, 10, blade());
    logMakeRate(20, 50, 10, mallet());

    $rows = comparison()->byDistance();

    expect($rows[0]['weight'])->toBe(20)
        ->and($rows[0]['first_attempts'])->toBe(100)
        ->and($rows[0]['second_attempts'])->toBe(20);
});

it('refuses to recommend before either putter has enough putts', function () {
    logMakeRate(60, 30, 10, blade());
    logMakeRate(40, 90, 10, mallet());

    $verdict = comparison()->verdict();

    expect($verdict['state'])->toBe('insufficient_data')
        ->and($verdict['putter'])->toBeNull()
        ->and($verdict['needed'])->toBe(60)
        ->and($verdict['message'])->toContain('Mallet');
});

it('refuses to recommend when the two putters never share distances', function () {
    logMakeRate(120, 40, 10, blade());
    logMakeRate(120, 80, 25, mallet());

    $verdict = comparison()->verdict();

    expect($verdict['state'])->toBe('insufficient_data')
        ->and($verdict['message'])->toContain('overlap');
});

it('calls a small gap too close rather than recommending on noise', function () {
    logMakeRate(200, 50, 10, blade());
    logMakeRate(200, 53, 10, mallet());

    $verdict = comparison()->verdict();

    expect($verdict['state'])->toBe('too_close')
        ->and($verdict['putter'])->toBeNull()
        ->and($verdict['gap'])->toBe(3.0)
        ->and($verdict['message'])->toContain('inside the noise');
});

it('recommends the putter that is clearly better at matched distances', function () {
    logMakeRate(100, 30, 10, blade());
    logMakeRate(100, 70, 10, mallet());

    $verdict = comparison()->verdict();

    expect($verdict['state'])->toBe('recommended')
        ->and($verdict['putter']->id)->toBe(mallet()->id)
        ->and($verdict['gap'])->toBe(40.0)
        ->and($verdict['sample'])->toBe(100)
        ->and($verdict['message'])->toContain('Play Mallet');
});

it('does not let volume at unmatched distances swing the recommendation', function () {
    // Even scoring where they overlap.
    logMakeRate(120, 50, 10, blade());
    logMakeRate(120, 50, 10, mallet());

    // A pile of tap-ins the mallet never took. Raw make rate would crown the blade.
    logMakeRate(200, 100, 2, blade());

    $comparison = comparison();

    expect($comparison->headline()[blade()->id]['make_percent'])->toBeGreaterThan(80.0)
        ->and($comparison->verdict()['state'])->toBe('too_close');
});

it('surfaces a short range edge as a strength', function () {
    logMakeRate(100, 60, 5, blade());
    logMakeRate(100, 85, 5, mallet());

    $strengths = comparison()->strengths();

    expect($strengths[mallet()->id])->not->toBeEmpty()
        ->and($strengths[blade()->id])->toBeEmpty();

    $headlines = array_column($strengths[mallet()->id], 'headline');

    expect($headlines)->toContain('Short range');
});

it('credits the putter whose misses stay on line', function () {
    logPutts(120, PuttResult::MissShort, 10, PuttContext::Inside, mallet());
    logPutts(120, PuttResult::MissLeft, 10, PuttContext::Inside, blade());

    $strengths = comparison()->strengths();
    $headlines = array_column($strengths[mallet()->id], 'headline');

    expect($headlines)->toContain('Holds the line');
});

it('reports the totals for each putter side by side', function () {
    logMakeRate(100, 40, 10, blade());
    logMakeRate(50, 80, 10, mallet(), PuttContext::Outside);

    $headline = comparison()->headline();

    expect($headline[blade()->id]['attempts'])->toBe(100)
        ->and($headline[blade()->id]['make_percent'])->toBe(40.0)
        ->and($headline[blade()->id]['inside']['attempts'])->toBe(100)
        ->and($headline[blade()->id]['outside']['attempts'])->toBe(0)
        ->and($headline[mallet()->id]['attempts'])->toBe(50)
        ->and($headline[mallet()->id]['outside']['make_percent'])->toBe(80.0);
});

it('breaks each putter down by inside and outside', function () {
    logMakeRate(40, 75, 10, blade(), PuttContext::Inside);
    logMakeRate(40, 50, 10, blade(), PuttContext::Outside);
    logMakeRate(40, 60, 10, mallet(), PuttContext::Inside);
    logMakeRate(40, 55, 10, mallet(), PuttContext::Outside);

    $breakdown = comparison()->contextBreakdown();

    expect($breakdown[blade()->id]['inside']['make_percent'])->toBe(75.0)
        ->and($breakdown[blade()->id]['outside']['make_percent'])->toBe(50.0)
        ->and($breakdown[blade()->id]['drop'])->toBe(25.0)
        ->and($breakdown[mallet()->id]['drop'])->toBe(5.0)
        ->and($breakdown[mallet()->id]['comparable'])->toBeTrue();
});

it('marks a putter uncomparable until it has putts on both sides', function () {
    logMakeRate(40, 75, 10, blade(), PuttContext::Inside);

    $breakdown = comparison()->contextBreakdown();

    expect($breakdown[blade()->id]['comparable'])->toBeFalse()
        ->and($breakdown[blade()->id]['outside']['attempts'])->toBe(0);
});

it('keeps the context breakdown whole even when the comparison is filtered', function () {
    logMakeRate(40, 75, 10, blade(), PuttContext::Inside);
    logMakeRate(40, 50, 10, blade(), PuttContext::Outside);

    $breakdown = comparison()
        ->inContext(PuttContext::Outside)
        ->contextBreakdown();

    expect($breakdown[blade()->id]['inside']['attempts'])->toBe(40)
        ->and($breakdown[blade()->id]['drop'])->toBe(25.0);
});

it('recommends a putter for outside putting on its own merits', function () {
    // The blade is better on the carpet, the mallet on real greens.
    logMakeRate(120, 80, 10, blade(), PuttContext::Inside);
    logMakeRate(120, 40, 10, blade(), PuttContext::Outside);
    logMakeRate(120, 50, 10, mallet(), PuttContext::Inside);
    logMakeRate(120, 70, 10, mallet(), PuttContext::Outside);

    $comparison = comparison();

    expect($comparison->inContext(PuttContext::Outside)->verdict()['putter']->id)->toBe(mallet()->id)
        ->and($comparison->inContext(PuttContext::Inside)->verdict()['putter']->id)->toBe(blade()->id);
});

it('counts only the scoped context in the filtered headline', function () {
    logMakeRate(60, 50, 10, blade(), PuttContext::Inside);
    logMakeRate(40, 50, 10, blade(), PuttContext::Outside);

    $headline = comparison()->inContext(PuttContext::Outside)->headline();

    expect($headline[blade()->id]['attempts'])->toBe(40);
});
