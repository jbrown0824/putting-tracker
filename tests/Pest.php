<?php

use App\Enums\ClockPosition;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Log a run of identical putts into a session of their own.
 */
function logPutts(
    int $count,
    PuttResult $result,
    int $distance,
    PuttContext $context = PuttContext::Inside,
    Putter $putter = Putter::Blade,
    ?ClockPosition $position = null,
): void {
    $session = PuttingSession::factory()->create([
        'context' => $context,
        'putter' => $putter,
    ]);

    Putt::factory()->count($count)->create([
        'putting_session_id' => $session->id,
        'result' => $result,
        'distance_ft' => $distance,
        'context' => $context,
        'putter' => $putter,
        // Null by default, matching every putt logged before positions existed.
        'clock_position' => $position,
        'slope' => $position?->slope(),
        'hit_at' => Carbon::now(),
    ]);
}

/**
 * A run of putts at one distance split between makes and one kind of miss, which is
 * how the comparison tests dial in a specific make rate.
 */
function logMakeRate(
    int $attempts,
    float $makePercent,
    int $distance,
    Putter $putter,
    PuttContext $context = PuttContext::Inside,
    PuttResult $miss = PuttResult::MissShort,
    ?ClockPosition $position = null,
): void {
    $made = (int) round($attempts * $makePercent / 100);

    if ($made > 0) {
        logPutts($made, PuttResult::Sunk, $distance, $context, $putter, $position);
    }

    if ($attempts - $made > 0) {
        logPutts($attempts - $made, $miss, $distance, $context, $putter, $position);
    }
}
