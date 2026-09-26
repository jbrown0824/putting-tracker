<?php

use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\PutterHeadType;
use App\Enums\PuttResult;
use App\Models\Putt;
use App\Models\Putter;
use App\Models\PuttingSession;
use App\Models\User;
use App\Services\PutterComparison;
use App\Services\PuttStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
/**
 * The player most tests are written from. Created on first use in each test.
 */
function testUser(): User
{
    return User::query()->orderBy('id')->first() ?? User::factory()->create();
}

function putterNamed(string $name, PutterHeadType $headType, ?User $user = null): Putter
{
    return ($user ?? testUser())->putters()->firstOrCreate(['name' => $name], ['head_type' => $headType]);
}

function blade(?User $user = null): Putter
{
    return putterNamed('Blade', PutterHeadType::Blade, $user);
}

function mallet(?User $user = null): Putter
{
    return putterNamed('Mallet', PutterHeadType::Mallet, $user);
}

function stats(?User $user = null): PuttStats
{
    return app(PuttStats::class)->forUser($user ?? testUser());
}

/**
 * Blade first, mallet second, so a positive gap favours the mallet.
 */
function comparison(): PutterComparison
{
    return app(PutterComparison::class)->forUser(testUser())->between(blade(), mallet());
}

function logPutts(
    int $count,
    PuttResult $result,
    int $distance,
    PuttContext $context = PuttContext::Inside,
    ?Putter $putter = null,
    ?ClockPosition $position = null,
): void {
    $putter ??= blade();

    $session = PuttingSession::factory()->for($putter)->create([
        'context' => $context,
    ]);

    Putt::factory()->count($count)->create([
        'putting_session_id' => $session->id,
        'result' => $result,
        'distance_ft' => $distance,
        'context' => $context,
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

/**
 * One putt as the phone's queue posts it to the sync endpoint.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puttPayload(array $overrides = []): array
{
    return array_merge([
        'uuid' => (string) Str::uuid(),
        'distance_ft' => 10,
        'result' => 'sunk',
        'context' => 'inside',
        'hit_at' => Carbon::now()->toIso8601String(),
    ], $overrides);
}

/**
 * Line misses tagged with why they missed, for the stroke-versus-read tests.
 */
function logCause(int $count, PuttResult $result, ?LineMissCause $cause, PuttContext $context = PuttContext::Inside, ?Putter $putter = null): void
{
    $session = PuttingSession::factory()->for($putter ?? blade())->create(['context' => $context]);

    Putt::factory()->count($count)->create([
        'putting_session_id' => $session->id,
        'result' => $result,
        'miss_cause' => $cause,
        'distance_ft' => 10,
        'context' => $context,
        'hit_at' => Carbon::now(),
    ]);
}
