<?php

namespace App\Enums;

/**
 * Where the ball sat relative to the hole, as a position on a clock face centred
 * on the hole.
 *
 * The orienting convention, without which none of this data means anything:
 * TWELVE O'CLOCK IS ALWAYS THE HIGH SIDE OF THE SLOPE. It is not a compass
 * direction and it is not the direction you happen to be facing. Twelve means the
 * ball is above the hole and the putt runs downhill; six means it is below the hole
 * and the putt runs uphill.
 *
 * One recorded position yields the slope, the break direction and the difficulty
 * band, which is why this replaces the separate slope and break inputs rather than
 * joining them.
 */
enum ClockPosition: string
{
    /** Twelve o'clock — ball above the hole, straight downhill. */
    case Above = 'above';

    /** One to two o'clock — downhill with the slope falling to the player's left. */
    case AboveRight = 'above_right';

    /** Three o'clock — level with the hole, pure sidehill. */
    case Right = 'right';

    /** Four to five o'clock — uphill with the slope falling to the player's left. */
    case BelowRight = 'below_right';

    /** Six o'clock — ball below the hole, straight uphill. */
    case Below = 'below';

    /** Seven to eight o'clock — uphill with the slope falling to the player's right. */
    case BelowLeft = 'below_left';

    /** Nine o'clock — level with the hole, pure sidehill. */
    case Left = 'left';

    /** Ten to eleven o'clock — downhill with the slope falling to the player's right. */
    case AboveLeft = 'above_left';

    /**
     * No fall line at all, which is the honest description of an indoor mat.
     *
     * Deliberately a recorded value rather than null. Null means the position was
     * never captured; Flat means it was captured and there is no slope. Collapsing
     * the two would make every indoor putt indistinguishable from an untagged one.
     */
    case Flat = 'flat';

    /**
     * Whether the putt runs uphill, downhill or across. This doubles as the coarse
     * three-way grouping used to stratify the adjusted make rate, where the eight
     * positions would fragment into cells too thin to mean anything.
     */
    public function slope(): PuttSlope
    {
        return match ($this) {
            self::Above, self::AboveRight, self::AboveLeft => PuttSlope::Downhill,
            self::Below, self::BelowRight, self::BelowLeft => PuttSlope::Uphill,
            self::Left, self::Right, self::Flat => PuttSlope::Flat,
        };
    }

    /**
     * Which way the putt breaks, from the player's point of view behind the ball.
     *
     * Derived rather than stored, because the geometry fixes it. With the fall line
     * running from twelve down to six, gravity's component across the putt line
     * always pushes the ball toward six o'clock. From the right-hand side of the
     * clock that is the player's left, so the putt breaks right to left; from the
     * left-hand side it is their right. Straight up and straight down the fall line
     * there is no across-component at all.
     */
    public function breakSide(): BreakSide
    {
        return match ($this) {
            self::AboveRight, self::Right, self::BelowRight => BreakSide::RightToLeft,
            self::AboveLeft, self::Left, self::BelowLeft => BreakSide::LeftToRight,
            self::Above, self::Below, self::Flat => BreakSide::Straight,
        };
    }

    /**
     * The reporting grouping. Mirrored positions share a band, so the two halves of
     * the clock pool into one sample instead of splitting it — the difference
     * between them is what breakSide() is for.
     */
    public function difficultyBand(): string
    {
        return match ($this) {
            self::Above => 'straight_downhill',
            self::AboveRight, self::AboveLeft => 'downhill_sidehill',
            self::Right, self::Left => 'sidehill',
            self::BelowRight, self::BelowLeft => 'uphill_sidehill',
            self::Below => 'straight_uphill',
            self::Flat => 'flat',
        };
    }

    /**
     * How the band reads in a sentence.
     */
    public function bandLabel(): string
    {
        return match ($this->difficultyBand()) {
            'straight_downhill' => 'Straight downhill',
            'downhill_sidehill' => 'Downhill sidehill',
            'sidehill' => 'Sidehill',
            'uphill_sidehill' => 'Uphill sidehill',
            'straight_uphill' => 'Straight uphill',
            default => 'Flat',
        };
    }

    /**
     * The clock reading, which is how a golfer describes where they are standing.
     */
    public function clockLabel(): string
    {
        return match ($this) {
            self::Above => '12 o\'clock',
            self::AboveRight => '1–2 o\'clock',
            self::Right => '3 o\'clock',
            self::BelowRight => '4–5 o\'clock',
            self::Below => '6 o\'clock',
            self::BelowLeft => '7–8 o\'clock',
            self::Left => '9 o\'clock',
            self::AboveLeft => '10–11 o\'clock',
            self::Flat => 'Flat',
        };
    }

    /**
     * What the putt actually does, for the stats pages where "1–2 o'clock" alone
     * would not tell you why it is hard.
     */
    public function label(): string
    {
        if ($this === self::Flat) {
            return 'Flat';
        }

        $break = $this->breakSide();

        return $break === BreakSide::Straight
            ? $this->bandLabel()
            : sprintf('%s, %s', $this->bandLabel(), $break->label());
    }

    /**
     * Rebuild a position from the slope and break_direction fields this enum
     * replaced.
     *
     * The two old fields together carry exactly the information one position does:
     * slope fixes which half of the clock the ball sat on, break_direction fixes
     * which side. Every valid pair maps to exactly one position, so this recovers
     * the data rather than guessing at it — no putt is invented and none is lost.
     *
     * Returns null when either half is missing, because a break with no slope
     * narrows the ball to three positions and picking one of them would be
     * fabrication.
     *
     * Note this holds under the same single-fall-line model the ring itself
     * assumes. A green with more than one contour can be uphill and break right to
     * left without the ball sitting neatly at four o'clock — but that is equally
     * true of a position tapped in by hand, so old and new rows stay consistent
     * with each other.
     */
    public static function fromLegacy(?string $breakDirection, ?PuttSlope $slope): ?self
    {
        if ($breakDirection === null || $slope === null) {
            return null;
        }

        return match ([$breakDirection, $slope->value]) {
            ['right_to_left', 'downhill'] => self::AboveRight,
            ['right_to_left', 'flat'] => self::Right,
            ['right_to_left', 'uphill'] => self::BelowRight,
            ['left_to_right', 'downhill'] => self::AboveLeft,
            ['left_to_right', 'flat'] => self::Left,
            ['left_to_right', 'uphill'] => self::BelowLeft,
            ['straight', 'downhill'] => self::Above,
            ['straight', 'uphill'] => self::Below,
            ['straight', 'flat'] => self::Flat,
            default => null,
        };
    }

    /**
     * The eight positions in clockwise order starting at twelve, so the input ring
     * and the heat map can lay themselves out without hard-coding the sequence.
     * Flat is excluded — it belongs in the middle of the ring, not on it.
     *
     * @return array<int, self>
     */
    public static function ring(): array
    {
        return [
            self::Above,
            self::AboveRight,
            self::Right,
            self::BelowRight,
            self::Below,
            self::BelowLeft,
            self::Left,
            self::AboveLeft,
        ];
    }

    /**
     * Where this position sits on the clock face, in degrees, measured the way SVG
     * trig measures it: zero at three o'clock, increasing clockwise. Twelve is
     * therefore -90.
     *
     * The angle belongs to the position; the radius and the colours belong to
     * whichever view is drawing it. Flat has no angle because it is not on the ring.
     */
    public function angle(): ?float
    {
        $index = array_search($this, self::ring(), true);

        return $index === false ? null : -90.0 + $index * 45.0;
    }

    /**
     * The neighbouring position going clockwise or anticlockwise around the ring,
     * for stepping round the hole during an around-the-world drill.
     *
     * Flat sits off the ring, so stepping from it enters the ring at twelve.
     */
    public function step(int $direction): self
    {
        $ring = self::ring();
        $index = array_search($this, $ring, true);

        if ($index === false) {
            return self::Above;
        }

        return $ring[($index + $direction + count($ring)) % count($ring)];
    }
}
