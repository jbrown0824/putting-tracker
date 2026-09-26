<?php

use App\Enums\GoalMetric;
use App\Enums\GoalPeriod;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Enums\SurfaceType;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Putt;
use App\Models\User;
use App\Services\ChallengeProgress;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/**
 * The original 2,000 putt challenge, expressed as two goals.
 */
function twoThousandChallenge(array $attributes = []): Challenge
{
    return challengeWith([
        'starts_on' => '2026-08-23',
        'ends_on' => '2026-09-19',
        ...$attributes,
    ], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 2000],
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 400, 'context' => PuttContext::Outside],
    ]);
}

function progressOf(Challenge $challenge): array
{
    return app(ChallengeProgress::class)->for($challenge);
}

it('reports progress and the pace needed to finish', function () {
    Carbon::setTestNow('2026-08-23 12:00:00');
    $challenge = twoThousandChallenge();

    logPutts(60, PuttResult::Sunk, 5);
    logPutts(40, PuttResult::MissShort, 5, PuttContext::Outside);

    $progress = progressOf($challenge);
    [$total, $outside] = $progress['goals'];

    expect($progress['days_total'])->toBe(28)
        ->and($progress['days_remaining'])->toBe(28)
        ->and($total['value'])->toBe(100)
        ->and($total['remaining'])->toBe(1900)
        ->and($total['pace']['per_day_needed'])->toBe(68)
        ->and($total['label'])->toBe('2,000 putts')
        ->and($outside['value'])->toBe(40)
        ->and($outside['label'])->toBe('400 putts outside');
});

it('tracks daily volume against the required pace', function () {
    Carbon::setTestNow('2026-08-24 12:00:00');
    $challenge = twoThousandChallenge();

    logPutts(10, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-08-23 09:00:00'));

    $series = progressOf($challenge)['goals'][0]['series'];

    expect($series)->toHaveCount(28)
        ->and($series[0]['value'])->toBe(10)
        ->and($series[0]['cumulative'])->toBe(10)
        ->and($series[0]['target_cumulative'])->toBe(71)
        ->and($series[27]['cumulative'])->toBeNull();
});

it('counts every putter when the challenge names none', function () {
    $challenge = challengeWith([], [['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 100]]);

    logPutts(30, PuttResult::Sunk, 10, PuttContext::Inside, blade());
    logPutts(20, PuttResult::Sunk, 10, PuttContext::Outside, mallet());

    expect(progressOf($challenge)['goals'][0]['value'])->toBe(50);
});

it('only counts the putters a challenge accepts', function () {
    $challenge = challengeWith([], [['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 100]]);
    $challenge->putters()->attach(mallet());

    logPutts(30, PuttResult::Sunk, 10, putter: blade());
    logPutts(20, PuttResult::Sunk, 10, putter: mallet());

    expect(progressOf($challenge->fresh())['goals'][0]['value'])->toBe(20);
});

it('counts one putt towards every challenge it matches', function () {
    $volume = challengeWith([], [['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 100]]);
    $daily = challengeWith([], [['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Daily, 'target' => 50]]);

    logPutts(12, PuttResult::Sunk, 10);

    expect(progressOf($volume)['goals'][0]['value'])->toBe(12)
        ->and(progressOf($daily)['goals'][0]['current']['value'])->toBe(12);
});

it('filters by context, surface type and distance', function () {
    $challenge = challengeWith([
        'contexts' => [PuttContext::Inside],
        'surface_types' => [SurfaceType::Mat],
        'min_distance_ft' => 20,
    ], [['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 100]]);

    logPutts(5, PuttResult::Sunk, 25);
    logPutts(5, PuttResult::Sunk, 10);
    logPutts(5, PuttResult::Sunk, 25, PuttContext::Outside);
    Putt::query()->where('distance_ft', 25)->where('context', PuttContext::Inside)->limit(3)->get()
        ->each->update(['surface_type' => SurfaceType::Mat]);

    expect(progressOf($challenge)['goals'][0]['value'])->toBe(3)
        ->and($challenge->describeFilters())->toBe('Any putter · Inside · Putting mat · 20 ft+');
});

it('ignores putts outside the challenge window and other players\' putts', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    $challenge = challengeWith(['starts_on' => '2026-09-05', 'ends_on' => '2026-09-20'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 100],
    ]);

    logPutts(4, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-04 12:00:00'));
    logPutts(6, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-06 12:00:00'));
    logPutts(9, PuttResult::Sunk, 10, putter: blade(User::factory()->create()), hitAt: Carbon::parse('2026-09-06 12:00:00'));

    expect(progressOf($challenge)['goals'][0]['value'])->toBe(6);
});

it('draws day boundaries in the player\'s time zone', function () {
    testUser()->update(['timezone' => 'America/Chicago']);
    Carbon::setTestNow('2026-09-06 02:00:00'); // 9pm on the 5th in Chicago
    $challenge = challengeWith(['starts_on' => '2026-09-05', 'ends_on' => '2026-09-20'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Daily, 'target' => 10],
    ]);

    // 11pm on the 4th in Chicago: before the challenge, though it is the 5th in UTC.
    logPutts(4, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-05 04:00:00'));
    // 8pm on the 5th in Chicago.
    logPutts(6, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-06 01:00:00'));

    $goal = progressOf($challenge->fresh())['goals'][0];

    expect($goal['current']['value'])->toBe(6)
        ->and($goal['current']['remaining'])->toBe(4)
        ->and($goal['periods']['elapsed'])->toBe(1);
});

it('reports how far today is from a daily target', function () {
    Carbon::setTestNow('2026-09-10 18:00:00');
    $challenge = challengeWith(['starts_on' => '2026-09-08', 'ends_on' => '2026-09-14'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Daily, 'target' => 100],
    ]);

    logPutts(100, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-08 12:00:00'));
    logPutts(120, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-09 12:00:00'));
    logPutts(62, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-10 12:00:00'));

    $goal = progressOf($challenge)['goals'][0];

    expect($goal['label'])->toBe('100 putts a day')
        ->and($goal['current'])->toMatchArray(['label' => 'Today', 'value' => 62, 'remaining' => 38, 'met' => false, 'days_left' => 1])
        ->and($goal['periods'])->toMatchArray(['met' => 2, 'missed' => 0, 'elapsed' => 3, 'total' => 7, 'required' => 7, 'streak' => 2])
        ->and($goal['status'])->toBe('on_track');
});

it('fails a daily goal once too many days are missed to recover', function () {
    Carbon::setTestNow('2026-09-10 18:00:00');
    $challenge = challengeWith(['starts_on' => '2026-09-08', 'ends_on' => '2026-09-14'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Daily, 'target' => 100, 'periods_required' => 6],
    ]);

    logPutts(100, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-10 12:00:00'));

    $goal = progressOf($challenge)['goals'][0];

    // Two days missed out of seven, with six required.
    expect($goal['periods']['missed'])->toBe(2)
        ->and($goal['status'])->toBe('failed')
        ->and($goal['label'])->toBe('100 putts a day, 6 days');
});

it('counts weekly goals in seven-day blocks from the start date', function () {
    Carbon::setTestNow('2026-09-16 12:00:00');
    $challenge = challengeWith(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Weekly, 'target' => 500],
    ]);

    logPutts(300, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-15 12:00:00'));
    logPutts(40, PuttResult::Sunk, 10, hitAt: Carbon::parse('2026-09-16 09:00:00'));

    $goal = progressOf($challenge)['goals'][0];

    // Week three runs from the 15th to the 21st.
    expect($goal['current'])->toMatchArray(['label' => 'This week', 'value' => 340, 'remaining' => 160, 'days_left' => 6])
        ->and($goal['periods']['total'])->toBe(5);
});

it('only credits a make rate once it has the sample behind it', function () {
    $challenge = challengeWith([], [
        ['metric' => GoalMetric::MakePercent, 'period' => GoalPeriod::Total, 'target' => 60, 'min_distance_ft' => 6, 'max_distance_ft' => 10, 'min_attempts' => 50],
    ]);

    logMakeRate(20, 80, 8, blade());
    logMakeRate(20, 10, 15, blade());

    $goal = progressOf($challenge)['goals'][0];

    expect($goal['value'])->toBe(80.0)
        ->and($goal['met'])->toBeFalse()
        ->and($goal['label'])->toBe('60% make rate from 6–10 ft (min 50 putts)');

    logMakeRate(40, 60, 8, blade());

    expect(progressOf($challenge)['goals'][0]['met'])->toBeTrue();
});

it('measures the longest run of makes in the order they were hit', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    $challenge = challengeWith([], [['metric' => GoalMetric::MakeStreak, 'period' => GoalPeriod::Total, 'target' => 10]]);
    $start = Carbon::now()->subHour();

    logPutts(4, PuttResult::Sunk, 5, hitAt: $start);
    logPutts(1, PuttResult::MissLong, 5, hitAt: $start->copy()->addMinute());
    logPutts(7, PuttResult::Sunk, 5, hitAt: $start->copy()->addMinutes(2));

    expect(progressOf($challenge)['goals'][0]['value'])->toBe(7);
});

it('counts days practised and completed drill runs', function () {
    Carbon::setTestNow('2026-09-10 18:00:00');
    $challenge = challengeWith(['kind' => 'drill', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'], [
        ['metric' => GoalMetric::DaysPractised, 'period' => GoalPeriod::Total, 'target' => 20],
        ['metric' => GoalMetric::DrillRunsCompleted, 'period' => GoalPeriod::Weekly, 'target' => 2],
    ]);

    logPutts(3, PuttResult::Sunk, 5, hitAt: Carbon::parse('2026-09-02 12:00:00'));
    logPutts(3, PuttResult::Sunk, 5, hitAt: Carbon::parse('2026-09-09 12:00:00'));
    ChallengeRun::factory()->count(2)->for($challenge)->create(['user_id' => testUser()->id, 'completed_at' => '2026-09-09 13:00:00']);
    ChallengeRun::factory()->for($challenge)->create(['user_id' => testUser()->id]);

    [$days, $runs] = progressOf($challenge)['goals'];

    expect($days['value'])->toBe(2)
        ->and($runs['current']['value'])->toBe(2)
        ->and($runs['current']['met'])->toBeTrue()
        ->and($runs['label'])->toBe('Finish the drill 2 times a week');
});

it('marks a total goal behind pace and complete', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    $challenge = challengeWith(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-10'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 100],
    ]);

    logPutts(50, PuttResult::Sunk, 5);
    expect(progressOf($challenge)['goals'][0]['status'])->toBe('behind');

    logPutts(50, PuttResult::Sunk, 5);
    expect(progressOf($challenge)['goals'][0]['status'])->toBe('complete');
});

it('fails an unfinished goal once the challenge ends and reports upcoming ones', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    $ended = challengeWith(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-05'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 100],
    ]);
    $upcoming = challengeWith(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-05'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Daily, 'target' => 100],
    ]);

    expect(progressOf($ended)['goals'][0]['status'])->toBe('failed')
        ->and(progressOf($ended)['state'])->toBe('ended')
        ->and(progressOf($upcoming)['goals'][0]['status'])->toBe('upcoming')
        ->and(progressOf($upcoming)['days_remaining'])->toBe(5);
});

it('runs an open-ended daily habit with no end or required count', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    $challenge = challengeWith(['starts_on' => '2026-09-08', 'ends_on' => null], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Daily, 'target' => 20],
    ]);

    logPutts(25, PuttResult::Sunk, 5, hitAt: Carbon::parse('2026-09-09 12:00:00'));
    logPutts(25, PuttResult::Sunk, 5, hitAt: Carbon::parse('2026-09-10 11:00:00'));

    $goal = progressOf($challenge)['goals'][0];

    expect($goal['status'])->toBe('on_track')
        ->and($goal['periods'])->toMatchArray(['met' => 2, 'missed' => 1, 'total' => null, 'required' => null, 'streak' => 2]);
});
