<?php

use App\Enums\DrillMissRule;
use App\Enums\DrillOrder;
use App\Models\Challenge;
use App\Services\DrillEngine;

/**
 * A ladder of the given distances, loaded the way the engine reads it.
 *
 * @param  array<int, int>  $distances
 * @param  array<string, mixed>  $rules
 */
function ladder(array $distances, array $rules = []): Challenge
{
    $challenge = Challenge::factory()->drill()->for(testUser())->create($rules);

    foreach ($distances as $index => $distance) {
        $challenge->steps()->create(['sort_order' => $index, 'distance_ft' => $distance]);
    }

    return $challenge->load('steps');
}

/**
 * @param  array<int, bool|array{0: bool, 1: int}>  $shots  a bool, or [made, step] for random drills
 * @return array<int, array{made: bool, step: int|null}>
 */
function shots(array $shots): array
{
    return array_map(fn ($shot): array => is_array($shot)
        ? ['made' => $shot[0], 'step' => $shot[1]]
        : ['made' => $shot, 'step' => null], $shots);
}

function replay(Challenge $challenge, array $shots): array
{
    return app(DrillEngine::class)->replay($challenge, shots($shots));
}

it('finishes a classic ladder with one make per rung', function () {
    $state = replay(ladder([3, 4, 5]), [true, true, true]);

    expect($state['completed'])->toBeTrue()
        ->and($state['completed_at_index'])->toBe(2)
        ->and($state['attempts'])->toBe(3);
});

it('sends a classic ladder back to the first rung on any miss', function () {
    $state = replay(ladder([3, 4, 5]), [true, true, false, true]);

    expect($state['completed'])->toBeFalse()
        ->and($state['step'])->toBe(1)
        ->and($state['furthest_step'])->toBe(2);
});

it('needs the required makes in a row on each rung', function () {
    $drill = ladder([3, 4], ['drill_makes_required' => 2, 'drill_on_miss' => DrillMissRule::Stay]);

    // A miss on a rung resets that rung's count but, under Stay, not the rung itself.
    $state = replay($drill, [true, false, true, true, true, true]);

    expect($state['completed'])->toBeTrue()
        ->and($state['attempts'])->toBe(6);
});

it('lets one rung demand more makes than the rest', function () {
    $drill = ladder([3, 4]);
    $drill->steps[1]->update(['makes_required' => 3]);

    expect(replay($drill->fresh('steps'), [true, true, true])['completed'])->toBeFalse()
        ->and(replay($drill->fresh('steps'), [true, true, true, true])['completed'])->toBeTrue();
});

it('gives two tries per rung with two attempts and one sunk', function () {
    $drill = ladder([3, 4], ['drill_attempts' => 2, 'drill_makes_required' => 1]);

    // A miss then a make clears the rung; a make first clears it straight away.
    $state = replay($drill, [false, true, true]);

    expect($state['completed'])->toBeTrue()
        ->and($state['attempts'])->toBe(3);
});

it('fails the rung once both tries are missed', function () {
    $drill = ladder([3, 4, 5], ['drill_attempts' => 2, 'drill_makes_required' => 1]);

    $state = replay($drill, [true, false, false]);

    expect($state['step'])->toBe(0)
        ->and($state['cleared'])->toBe([])
        ->and($state['step_putts'])->toBe(0);
});

it('needs every putt sunk when attempts equal the sunk required', function () {
    $drill = ladder([3, 4], ['drill_attempts' => 2, 'drill_makes_required' => 2]);

    // Sinking one of two on the second rung fails it and, on a classic ladder, restarts.
    expect(replay($drill, [true, true, true, false])['step'])->toBe(0)
        ->and(replay($drill, [true, true, true, true])['completed'])->toBeTrue();
});

it('fails the rung as soon as the remaining attempts cannot be enough', function () {
    $drill = ladder([3, 4], ['drill_attempts' => 3, 'drill_makes_required' => 2]);

    // Two misses out of three leave too few tries, so the third is never needed.
    expect(replay($drill, [false, false])['step_putts'])->toBe(0)
        ->and(replay($drill, [false, true, true, true, true])['completed'])->toBeTrue();
});

it('lets a rung that needs more sunk than the drill allows attempts take that many', function () {
    $drill = ladder([3, 4], ['drill_attempts' => 2, 'drill_makes_required' => 1]);
    $drill->steps[1]->update(['makes_required' => 3]);

    expect(replay($drill->fresh('steps'), [true, true, true, false])['step'])->toBe(0)
        ->and(replay($drill->fresh('steps'), [true, true, true, true])['completed'])->toBeTrue();
});

it('drops one rung on a miss when told to', function () {
    $state = replay(ladder([3, 4, 5], ['drill_on_miss' => DrillMissRule::StepBack]), [true, true, false]);

    expect($state['step'])->toBe(1)
        ->and($state['cleared'])->toBe([0]);
});

it('stays on the rung on a miss when told to', function () {
    $state = replay(ladder([3, 4, 5], ['drill_on_miss' => DrillMissRule::Stay]), [true, false, true, true]);

    expect($state['completed'])->toBeTrue();
});

it('runs several rounds before the drill is done', function () {
    $drill = ladder([3, 4], ['drill_rounds' => 2]);

    expect(replay($drill, [true, true])['completed'])->toBeFalse()
        ->and(replay($drill, [true, true])['round'])->toBe(1)
        ->and(replay($drill, [true, true, true, true])['completed'])->toBeTrue();
});

it('ignores anything hit after the drill was finished', function () {
    $state = replay(ladder([3]), [true, false, false]);

    expect($state['completed'])->toBeTrue()
        ->and($state['completed_at_index'])->toBe(0)
        ->and($state['attempts'])->toBe(1);
});

it('takes the recorded step on a random drill and refuses repeats', function () {
    $drill = ladder([3, 4, 5], ['drill_order' => DrillOrder::Random, 'drill_on_miss' => DrillMissRule::Stay]);

    $state = replay($drill, [[true, 2], [true, 2], [true, 0], [false, 1], [true, 1]]);

    expect($state['completed'])->toBeTrue()
        ->and($state['attempts'])->toBe(5);
});
