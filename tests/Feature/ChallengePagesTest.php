<?php

use App\Enums\ChallengeKind;
use App\Enums\DrillMissRule;
use App\Enums\GoalMetric;
use App\Enums\GoalPeriod;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->actingAs(testUser()));
afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function challengeForm(array $overrides = []): array
{
    return [
        'name' => 'Winter mat',
        'kind' => 'goals',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-30',
        'goals' => [['metric' => 'attempts', 'period' => 'daily', 'target' => 100]],
        ...$overrides,
    ];
}

it('creates a goals challenge with its filters', function () {
    $this->post(route('challenges.store'), challengeForm([
        'putter_ids' => [mallet()->id],
        'contexts' => ['inside'],
        'surface_types' => ['mat'],
        'min_distance_ft' => 20,
        'goals' => [
            ['metric' => 'attempts', 'period' => 'daily', 'target' => 100, 'periods_required' => 25],
            ['metric' => 'make_percent', 'period' => 'total', 'target' => 15, 'min_attempts' => 200],
        ],
    ]))->assertRedirect();

    $challenge = Challenge::query()->sole();

    expect($challenge->user_id)->toBe(testUser()->id)
        ->and($challenge->putters->modelKeys())->toBe([mallet()->id])
        ->and($challenge->contexts->all())->toBe([PuttContext::Inside])
        ->and($challenge->min_distance_ft)->toBe(20)
        ->and($challenge->goals)->toHaveCount(2)
        ->and($challenge->goals[0]->periods_required)->toBe(25)
        ->and($challenge->goals[1]->metric)->toBe(GoalMetric::MakePercent);
});

it('stores no filter as any rather than an empty list', function () {
    $this->post(route('challenges.store'), challengeForm())->assertRedirect();

    $challenge = Challenge::query()->sole();

    expect($challenge->contexts)->toBeNull()
        ->and($challenge->surface_types)->toBeNull()
        ->and($challenge->putters)->toBeEmpty();
});

it('creates a classic ladder drill with one make per rung by default', function () {
    $this->post(route('challenges.store'), challengeForm([
        'kind' => 'drill',
        'goals' => [],
        'steps' => collect(range(3, 10))->map(fn (int $feet): array => ['distance_ft' => $feet])->all(),
        'drill_on_miss' => 'restart',
        'drill_order' => 'sequential',
    ]))->assertRedirect();

    $drill = Challenge::query()->sole();

    expect($drill->kind)->toBe(ChallengeKind::Drill)
        ->and($drill->steps->pluck('distance_ft')->all())->toBe(range(3, 10))
        ->and($drill->drill_on_miss)->toBe(DrillMissRule::Restart)
        ->and($drill->drill_makes_required)->toBe(1)
        ->and($drill->drill_attempts)->toBe(1);
});

it('stores attempts and sunk per rung on a drill', function () {
    $this->post(route('challenges.store'), challengeForm([
        'kind' => 'drill',
        'goals' => [],
        'steps' => [['distance_ft' => 3], ['distance_ft' => 4]],
        'drill_on_miss' => 'restart',
        'drill_order' => 'sequential',
        'drill_attempts' => 2,
        'drill_makes_required' => 1,
    ]))->assertRedirect();

    $drill = Challenge::query()->sole();

    expect($drill->drill_attempts)->toBe(2)
        ->and($drill->drill_makes_required)->toBe(1);

    $this->get(route('challenges.show', $drill))->assertOk()->assertSee('3ft · 1 of 2');
});

it('rejects a drill that needs more sunk than it has attempts', function () {
    $this->post(route('challenges.store'), challengeForm([
        'kind' => 'drill',
        'goals' => [],
        'steps' => [['distance_ft' => 3]],
        'drill_on_miss' => 'restart',
        'drill_order' => 'sequential',
        'drill_attempts' => 2,
        'drill_makes_required' => 3,
    ]))->assertSessionHasErrors('drill_makes_required');
});

it('requires a goal on a goals challenge and a step on a drill', function () {
    $this->post(route('challenges.store'), challengeForm(['goals' => []]))->assertSessionHasErrors('goals');
    $this->post(route('challenges.store'), challengeForm(['kind' => 'drill', 'drill_on_miss' => 'restart', 'drill_order' => 'sequential']))
        ->assertSessionHasErrors('steps');
});

it('rejects impossible settings', function () {
    $this->post(route('challenges.store'), challengeForm([
        'ends_on' => '2026-08-01',
        'putter_ids' => [blade(User::factory()->create())->id],
        'goals' => [['metric' => 'make_percent', 'period' => 'total', 'target' => 140]],
    ]))->assertSessionHasErrors(['ends_on', 'putter_ids.0', 'goals.0.target']);
});

it('replaces goals and steps when a challenge is edited', function () {
    $challenge = challengeWith([], [['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 10]]);

    $this->put(route('challenges.update', $challenge), challengeForm([
        'goals' => [['metric' => 'makes', 'period' => 'weekly', 'target' => 50]],
    ]))->assertRedirect(route('challenges.show', $challenge));

    expect($challenge->fresh()->goals->map->describe()->all())->toBe(['50 makes a week']);
});

it('shows progress on the challenge page', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    $challenge = challengeWith(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'], [
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Daily, 'target' => 100],
        ['metric' => GoalMetric::Attempts, 'period' => GoalPeriod::Total, 'target' => 2000],
    ]);
    logPutts(62, PuttResult::Sunk, 10);

    $this->get(route('challenges.show', $challenge))
        ->assertOk()
        ->assertSee('100 putts a day')
        ->assertSee('38 to go')
        ->assertSee('Focus in the logger');
});

it('shows a drill\'s steps and record', function () {
    $drill = Challenge::factory()->drill()->for(testUser())->create();
    $drill->steps()->create(['sort_order' => 0, 'distance_ft' => 3]);
    ChallengeRun::factory()->for($drill)->create(['completed_at' => now()]);

    $this->get(route('challenges.show', $drill))
        ->assertOk()
        ->assertSee('Start the drill')
        ->assertSee('3ft');
});

it('groups the challenge list into running, upcoming and finished', function () {
    Carbon::setTestNow('2026-09-10 12:00:00');
    challengeWith(['name' => 'Now', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'], [['metric' => 'attempts', 'period' => 'total', 'target' => 10]]);
    challengeWith(['name' => 'Later', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-30'], [['metric' => 'attempts', 'period' => 'total', 'target' => 10]]);
    challengeWith(['name' => 'Before', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-30'], [['metric' => 'attempts', 'period' => 'total', 'target' => 10]]);

    $this->get(route('challenges.index'))
        ->assertOk()
        ->assertSeeInOrder(['Running', 'Now', 'Coming up', 'Later', 'Finished', 'Before']);
});

it('archives and deletes a challenge without touching putts', function () {
    $challenge = challengeWith([], [['metric' => 'attempts', 'period' => 'total', 'target' => 10]]);
    logPutts(5, PuttResult::Sunk, 10);

    $this->patch(route('challenges.archive', $challenge))->assertRedirect();
    expect($challenge->fresh()->archived_at)->not->toBeNull();

    $this->delete(route('challenges.destroy', $challenge))->assertRedirect(route('challenges.index'));
    expect(Challenge::query()->count())->toBe(0)
        ->and(testUser()->putts()->count())->toBe(5);
});

it('hides other players\' challenges', function () {
    $theirs = Challenge::factory()->create();

    $this->get(route('challenges.show', $theirs))->assertNotFound();
    $this->get(route('challenges.edit', $theirs))->assertNotFound();
    $this->put(route('challenges.update', $theirs), challengeForm())->assertNotFound();
    $this->delete(route('challenges.destroy', $theirs))->assertNotFound();
});

it('hands the logger every running challenge', function () {
    challengeWith(['name' => 'Daily hundred'], [['metric' => 'attempts', 'period' => 'daily', 'target' => 100]]);
    challengeWith(['name' => 'Next month', 'starts_on' => now()->addMonth()], [['metric' => 'attempts', 'period' => 'daily', 'target' => 100]]);

    $this->get(route('log'))
        ->assertOk()
        ->assertSee('Daily hundred')
        ->assertDontSee('Next month');
});

it('renders the create and edit forms', function () {
    $challenge = challengeWith([], [['metric' => 'attempts', 'period' => 'total', 'target' => 10]]);

    $this->get(route('challenges.create'))->assertOk()->assertSee('Start from');
    $this->get(route('challenges.edit', $challenge))->assertOk()->assertSee('Save challenge');
});
