<?php

use App\Enums\BreakSide;
use App\Enums\ClockPosition;
use App\Enums\PuttSlope;

it('reads the slope from which half of the clock the ball sits on', function () {
    expect(ClockPosition::Above->slope())->toBe(PuttSlope::Downhill)
        ->and(ClockPosition::AboveRight->slope())->toBe(PuttSlope::Downhill)
        ->and(ClockPosition::AboveLeft->slope())->toBe(PuttSlope::Downhill)
        ->and(ClockPosition::Below->slope())->toBe(PuttSlope::Uphill)
        ->and(ClockPosition::BelowRight->slope())->toBe(PuttSlope::Uphill)
        ->and(ClockPosition::BelowLeft->slope())->toBe(PuttSlope::Uphill)
        ->and(ClockPosition::Left->slope())->toBe(PuttSlope::Flat)
        ->and(ClockPosition::Right->slope())->toBe(PuttSlope::Flat)
        ->and(ClockPosition::Flat->slope())->toBe(PuttSlope::Flat);
});

/**
 * The one piece of non-obvious geometry in the feature, so it is asserted from both
 * sides rather than trusted.
 *
 * With the fall line running twelve down to six, gravity pushes the ball toward six
 * o'clock. Standing at three and putting west, six o'clock is on your left, so the
 * putt breaks right to left. Standing at nine and putting east, the same six o'clock
 * is on your right, so it breaks the other way.
 */
it('breaks toward the low side, which flips between the two halves of the clock', function () {
    expect(ClockPosition::Right->breakSide())->toBe(BreakSide::RightToLeft)
        ->and(ClockPosition::AboveRight->breakSide())->toBe(BreakSide::RightToLeft)
        ->and(ClockPosition::BelowRight->breakSide())->toBe(BreakSide::RightToLeft)
        ->and(ClockPosition::Left->breakSide())->toBe(BreakSide::LeftToRight)
        ->and(ClockPosition::AboveLeft->breakSide())->toBe(BreakSide::LeftToRight)
        ->and(ClockPosition::BelowLeft->breakSide())->toBe(BreakSide::LeftToRight);
});

it('has no break straight up or down the fall line', function () {
    expect(ClockPosition::Above->breakSide())->toBe(BreakSide::Straight)
        ->and(ClockPosition::Below->breakSide())->toBe(BreakSide::Straight)
        ->and(ClockPosition::Flat->breakSide())->toBe(BreakSide::Straight);
});

/**
 * This is the property the whole feature turns on. The two hardest positions sit on
 * opposite break directions, so a player weak at both is weak at the slope rather
 * than at reading one way — which is precisely what the insight disentangles.
 */
it('puts the two downhill sidehill positions in one band but opposite breaks', function () {
    expect(ClockPosition::AboveRight->difficultyBand())->toBe('downhill_sidehill')
        ->and(ClockPosition::AboveLeft->difficultyBand())->toBe('downhill_sidehill')
        ->and(ClockPosition::AboveRight->breakSide())
        ->not->toBe(ClockPosition::AboveLeft->breakSide());
});

it('pools mirrored positions into the same band', function () {
    expect(ClockPosition::BelowRight->difficultyBand())->toBe('uphill_sidehill')
        ->and(ClockPosition::BelowLeft->difficultyBand())->toBe('uphill_sidehill')
        ->and(ClockPosition::Left->difficultyBand())->toBe('sidehill')
        ->and(ClockPosition::Right->difficultyBand())->toBe('sidehill')
        ->and(ClockPosition::Above->difficultyBand())->toBe('straight_downhill')
        ->and(ClockPosition::Below->difficultyBand())->toBe('straight_uphill')
        ->and(ClockPosition::Flat->difficultyBand())->toBe('flat');
});

it('lays the ring out clockwise from twelve', function () {
    expect(array_map(fn (ClockPosition $p): string => $p->value, ClockPosition::ring()))
        ->toBe(['above', 'above_right', 'right', 'below_right', 'below', 'below_left', 'left', 'above_left']);
});

it('places twelve at the top and six at the bottom of the drawn ring', function () {
    // SVG angles: -90 is straight up, 90 is straight down.
    expect(ClockPosition::Above->angle())->toBe(-90.0)
        ->and(ClockPosition::Right->angle())->toBe(0.0)
        ->and(ClockPosition::Below->angle())->toBe(90.0)
        ->and(ClockPosition::Flat->angle())->toBeNull();
});

it('walks round the ring and wraps at both ends', function () {
    expect(ClockPosition::Above->step(1))->toBe(ClockPosition::AboveRight)
        ->and(ClockPosition::Above->step(-1))->toBe(ClockPosition::AboveLeft)
        ->and(ClockPosition::AboveLeft->step(1))->toBe(ClockPosition::Above);
});

it('enters the ring at twelve when stepping off flat', function () {
    expect(ClockPosition::Flat->step(1))->toBe(ClockPosition::Above)
        ->and(ClockPosition::Flat->step(-1))->toBe(ClockPosition::Above);
});

it('describes a position as both a clock reading and what the putt does', function () {
    expect(ClockPosition::AboveRight->clockLabel())->toBe('1–2 o\'clock')
        ->and(ClockPosition::AboveRight->label())->toBe('Downhill sidehill, breaking R→L')
        ->and(ClockPosition::Below->label())->toBe('Straight uphill')
        ->and(ClockPosition::Flat->label())->toBe('Flat');
});

/**
 * The pair of fields this enum replaced carries exactly the same information, so a
 * putt that recorded both can be recovered rather than written off. Every valid
 * combination is asserted, because getting one arm backwards would silently mirror
 * real practice data.
 */
it('rebuilds every position from the slope and break it replaced', function () {
    expect(ClockPosition::fromLegacy('right_to_left', PuttSlope::Downhill))->toBe(ClockPosition::AboveRight)
        ->and(ClockPosition::fromLegacy('right_to_left', PuttSlope::Flat))->toBe(ClockPosition::Right)
        ->and(ClockPosition::fromLegacy('right_to_left', PuttSlope::Uphill))->toBe(ClockPosition::BelowRight)
        ->and(ClockPosition::fromLegacy('left_to_right', PuttSlope::Downhill))->toBe(ClockPosition::AboveLeft)
        ->and(ClockPosition::fromLegacy('left_to_right', PuttSlope::Flat))->toBe(ClockPosition::Left)
        ->and(ClockPosition::fromLegacy('left_to_right', PuttSlope::Uphill))->toBe(ClockPosition::BelowLeft)
        ->and(ClockPosition::fromLegacy('straight', PuttSlope::Downhill))->toBe(ClockPosition::Above)
        ->and(ClockPosition::fromLegacy('straight', PuttSlope::Uphill))->toBe(ClockPosition::Below)
        ->and(ClockPosition::fromLegacy('straight', PuttSlope::Flat))->toBe(ClockPosition::Flat);
});

it('round-trips: every position rebuilds itself from what it derives', function () {
    foreach (ClockPosition::cases() as $position) {
        expect(ClockPosition::fromLegacy($position->breakSide()->value, $position->slope()))
            ->toBe($position);
    }
});

it('refuses to guess a position when either half is missing', function () {
    // A break with no slope leaves three candidates; picking one is fabrication.
    expect(ClockPosition::fromLegacy('right_to_left', null))->toBeNull()
        ->and(ClockPosition::fromLegacy(null, PuttSlope::Uphill))->toBeNull()
        ->and(ClockPosition::fromLegacy(null, null))->toBeNull()
        ->and(ClockPosition::fromLegacy('sideways', PuttSlope::Uphill))->toBeNull();
});
