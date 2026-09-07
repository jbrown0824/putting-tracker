<?php

namespace App\Actions;

use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use App\Models\Challenge;
use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class GenerateDemoPutts
{
    public const LADDER = [3, 5, 8, 10, 12, 15, 20, 25, 30];

    /**
     * Named tendencies so a reseed can reproduce a specific weakness on demand.
     *
     * skill:          drives the distance at which the putter is a coin flip
     *                 (skill / 12 feet), so 90 is a half-distance of 7.5 ft.
     * decay:          how steeply the make rate falls away with distance.
     * speed_tendency: -1 always leaves short, +1 always runs past.
     * line_tendency:  -1 always misses left, +1 always misses right.
     *
     * @var array<string, array<string, float>>
     */
    public const PROFILES = [
        'lag' => ['skill' => 94, 'decay' => 2.0, 'speed_tendency' => -0.8, 'line_tendency' => 0.05, 'speed_share' => 0.7],
        'charger' => ['skill' => 96, 'decay' => 2.0, 'speed_tendency' => 0.7, 'line_tendency' => -0.05, 'speed_share' => 0.68],
        'puller' => ['skill' => 90, 'decay' => 2.05, 'speed_tendency' => -0.1, 'line_tendency' => -0.75, 'speed_share' => 0.32],
        'pusher' => ['skill' => 90, 'decay' => 2.05, 'speed_tendency' => 0.1, 'line_tendency' => 0.75, 'speed_share' => 0.32],
        'elite' => ['skill' => 130, 'decay' => 1.8, 'speed_tendency' => 0.15, 'line_tendency' => 0.0, 'speed_share' => 0.5],
        'struggling' => ['skill' => 62, 'decay' => 2.3, 'speed_tendency' => -0.5, 'line_tendency' => -0.3, 'speed_share' => 0.55],
    ];

    /**
     * Deltas applied on top of the base profile so the two putters behave differently
     * enough for the comparison page to have something real to find.
     *
     * Modelled on how the heads actually differ: a mallet's higher MOI resists twisting
     * on an off-centre strike, so fewer of its misses are left or right and a larger
     * share are speed. A blade gives up more to the pull.
     *
     * @var array<string, array<string, float>>
     */
    public const PUTTER_MODIFIERS = [
        'blade' => ['skill' => -5.0, 'decay' => 0.05, 'line_tendency' => -0.25, 'speed_share' => -0.06, 'read_share' => -0.08],
        'mallet' => ['skill' => 5.0, 'decay' => -0.05, 'line_tendency' => 0.05, 'speed_share' => 0.08, 'read_share' => 0.08],
    ];

    /**
     * Share of sessions played with the blade. Deliberately not 50/50 so the
     * distance-matched weighting in PutterComparison is actually exercised.
     */
    private const BLADE_SESSION_SHARE = 55;

    /**
     * How much easier or harder each band is, as a multiplier on the half-distance.
     * Below 1 pulls the make-rate curve in and makes the position harder.
     *
     * Ordered the way real putts behave: downhill sidehill is the hardest putt in
     * golf because speed and break compound, and straight uphill is the most
     * forgiving because pace covers a multitude of sins.
     *
     * @var array<string, float>
     */
    private const POSITION_DIFFICULTY = [
        'straight_downhill' => 0.82,
        'downhill_sidehill' => 0.68,
        'sidehill' => 0.88,
        'uphill_sidehill' => 1.06,
        'straight_uphill' => 1.12,
        'flat' => 1.0,
    ];

    /**
     * Share of indoor spots played on the flat. A mat has no fall line, so almost
     * every indoor putt is genuinely flat rather than merely untagged.
     */
    private const INDOOR_FLAT_SHARE = 88;

    /**
     * Wipe existing putts and generate a fresh random dataset.
     *
     * Nothing on a putt says whether a human or this seeder logged it, so the
     * guard cannot be selective: it refuses to delete anything at all unless the
     * caller has explicitly asked for a replacement. This command has destroyed a
     * real practice session once already, and an accidental reseed is not
     * recoverable.
     *
     * @return array<string, mixed> a summary of what was generated
     *
     * @throws \RuntimeException when putts already exist and no replacement was asked for
     */
    public function execute(
        int $days = 14,
        ?string $profileName = null,
        bool $alignChallengeWindow = true,
        bool $replaceExisting = false,
    ): array {
        $profile = $this->resolveProfile($profileName);
        $existing = Putt::query()->count();

        if ($existing > 0 && ! $replaceExisting) {
            throw new \RuntimeException(sprintf(
                'There are already %d putts logged. Seeding deletes every one of them, including any you hit yourself. Pass --fresh if that is really what you want.',
                $existing,
            ));
        }

        Putt::query()->delete();
        PuttingSession::query()->delete();

        $endsOn = Carbon::today();
        $startsOn = $endsOn->copy()->subDays($days - 1);

        if ($alignChallengeWindow) {
            $this->alignChallengeTo($startsOn);
        }

        $rows = [];
        $sessionCount = 0;
        $perPutter = [Putter::Blade->value => 0, Putter::Mallet->value => 0];

        for ($day = 0; $day < $days; $day++) {
            $date = $startsOn->copy()->addDays($day);

            // A rest day now and then, so the pace chart has a realistic shape.
            if (mt_rand(1, 100) <= 12) {
                continue;
            }

            foreach ($this->sessionPlanFor($date) as [$context, $putter, $startedAt, $volume]) {
                $session = PuttingSession::query()->create([
                    'context' => $context,
                    'putter' => $putter,
                    'location' => $context === PuttContext::Outside ? $this->randomLocation() : null,
                    'surface' => $context === PuttContext::Outside ? 'practice green' : 'carpet',
                    'started_at' => $startedAt,
                ]);

                $sessionCount++;
                $perPutter[$putter->value] += $volume;
                $putterProfile = $this->applyPutterModifier($profile, $putter);

                // Practice happens in spots: you stand somewhere, hit a handful, then
                // move. Rolling a position per putt would scatter them in a way no
                // one actually putts, and would make every position look identical.
                $logged = 0;

                while ($logged < $volume) {
                    $spot = $this->rollSpot($context);
                    $run = min($volume - $logged, mt_rand(3, 8));

                    for ($i = 0; $i < $run; $i++, $logged++) {
                        $rows[] = $this->buildPutt(
                            $session,
                            $context,
                            $putter,
                            $spot,
                            $startedAt->copy()->addSeconds($logged * 35),
                            $putterProfile,
                        );
                    }
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Putt::query()->insert($chunk);
        }

        return [
            'profile' => $profile['name'],
            'putts' => count($rows),
            'per_putter' => $perPutter,
            'sessions' => $sessionCount,
            'days' => $days,
            'starts_on' => $startsOn->toDateString(),
            'speed_tendency' => $profile['speed_tendency'],
            'line_tendency' => $profile['line_tendency'],
            'outdoor_penalty' => $profile['outdoor_penalty'],
        ];
    }

    /** @return array<string, mixed> */
    private function resolveProfile(?string $name): array
    {
        if ($name !== null && $name !== 'random') {
            $profile = self::PROFILES[$name] ?? throw new \InvalidArgumentException(
                sprintf('Unknown profile [%s]. Available: %s, random.', $name, implode(', ', array_keys(self::PROFILES))),
            );

            return [...$profile, 'name' => $name] + $this->randomExtras();
        }

        return [
            'name' => 'random',
            'skill' => mt_rand(700, 1250) / 10,
            'decay' => mt_rand(170, 240) / 100,
            'speed_tendency' => mt_rand(-80, 80) / 100,
            'line_tendency' => mt_rand(-70, 70) / 100,
            'speed_share' => mt_rand(30, 72) / 100,
            ...$this->randomExtras(),
        ];
    }

    /** @return array<string, float> */
    private function randomExtras(): array
    {
        return [
            'outdoor_penalty' => mt_rand(4, 22),
            'lip_out_rate' => mt_rand(2, 7),
            // Share of indoor line misses that are misread breaks rather than the stroke.
            'read_share' => mt_rand(20, 45) / 100,
            // Not every putt gets classified: the simple dial leaves the cause blank.
            'classified_share' => mt_rand(60, 90) / 100,
        ];
    }

    /**
     * @return array<int, array{0: PuttContext, 1: Putter, 2: Carbon, 3: int}>
     */
    private function sessionPlanFor(Carbon $date): array
    {
        $plan = [[PuttContext::Inside, $this->randomPutter(), $date->copy()->setTime(mt_rand(18, 21), mt_rand(0, 50)), mt_rand(25, 80)]];

        // Outside practice only happens on some days.
        if (mt_rand(1, 100) <= 55) {
            $plan[] = [PuttContext::Outside, $this->randomPutter(), $date->copy()->setTime(mt_rand(8, 16), mt_rand(0, 50)), mt_rand(10, 35)];
        }

        return $plan;
    }

    private function randomPutter(): Putter
    {
        return mt_rand(1, 100) <= self::BLADE_SESSION_SHARE ? Putter::Blade : Putter::Mallet;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function applyPutterModifier(array $profile, Putter $putter): array
    {
        foreach (self::PUTTER_MODIFIERS[$putter->value] as $key => $delta) {
            $profile[$key] += $delta;
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function buildPutt(PuttingSession $session, PuttContext $context, Putter $putter, array $spot, Carbon $hitAt, array $profile): array
    {
        $distance = $spot['distance'];
        $position = $spot['position'];
        $result = $this->rollResult($distance, $context, $position, $profile);

        return [
            'uuid' => (string) Str::uuid(),
            'putting_session_id' => $session->id,
            'distance_ft' => $distance,
            'result' => $result->value,
            'miss_cause' => $this->rollMissCause($result, $context, $profile)?->value,
            'context' => $context->value,
            'putter' => $putter->value,
            // Derived rather than rolled, exactly as RecordPutts derives it, so the
            // seeded data cannot contradict itself.
            'slope' => $position->slope()->value,
            'clock_position' => $position->value,
            'notes' => null,
            'hit_at' => $hitAt->toDateTimeString(),
            'created_at' => $hitAt->toDateTimeString(),
            'updated_at' => $hitAt->toDateTimeString(),
        ];
    }

    /**
     * Real make rates follow a curve that is steep up close and flattens out long —
     * roughly 90% from 3 ft, half from 7 or 8 ft, single digits from 30. A straight
     * line through those points is far too generous in the middle, which made every
     * generated dataset look tour-standard from 12 ft.
     *
     * skill shifts the whole curve out or in; decay steepens or flattens it.
     *
     * @param  array<string, mixed>  $profile
     */
    private function rollResult(int $distance, PuttContext $context, ClockPosition $position, array $profile): PuttResult
    {
        $halfDistance = $profile['skill'] / 12;

        if ($context === PuttContext::Outside) {
            $halfDistance *= 1 - ($profile['outdoor_penalty'] / 100);
        }

        $halfDistance *= self::POSITION_DIFFICULTY[$position->difficultyBand()];

        $makeChance = 100 / (1 + ($distance / max(1.5, $halfDistance)) ** $profile['decay']);

        $makeChance = max(1.0, min(98.0, $makeChance));
        $roll = mt_rand(0, 10000) / 100;

        if ($roll < $makeChance) {
            return PuttResult::Sunk;
        }

        if ($roll < $makeChance + $profile['lip_out_rate']) {
            return PuttResult::LipOut;
        }

        // Longer putts fail on speed more often than on line, and so do downhill
        // ones — pace is the thing a slope takes away from you first.
        $downhill = $position->slope() === PuttSlope::Downhill ? 0.12 : 0.0;
        $speedShare = min(0.9, $profile['speed_share'] + ($distance * 0.012) + $downhill);

        if (mt_rand(0, 100) / 100 < $speedShare) {
            $shortChance = 0.5 - ($profile['speed_tendency'] * 0.42);

            return mt_rand(0, 100) / 100 < $shortChance ? PuttResult::MissShort : PuttResult::MissLong;
        }

        $leftChance = 0.5 - ($profile['line_tendency'] * 0.42);

        return mt_rand(0, 100) / 100 < $leftChance ? PuttResult::MissLeft : PuttResult::MissRight;
    }

    /**
     * A flat carpet barely breaks, so indoors almost every line miss is the stroke.
     * Outdoors the read starts costing real strokes, which is the whole reason the
     * two are worth separating.
     *
     * @param  array<string, mixed>  $profile
     */
    private function rollMissCause(PuttResult $result, PuttContext $context, array $profile): ?LineMissCause
    {
        if (! LineMissCause::appliesTo($result)) {
            return null;
        }

        if (mt_rand(0, 10000) / 10000 > $profile['classified_share']) {
            return null;
        }

        $readShare = $context === PuttContext::Outside
            ? min(0.85, $profile['read_share'] + 0.3)
            : $profile['read_share'];

        return mt_rand(0, 10000) / 10000 < $readShare ? LineMissCause::Read : LineMissCause::Stroke;
    }

    /**
     * A spot to putt from: one distance and one position, held for a run of putts.
     *
     * @return array{distance: int, position: ClockPosition}
     */
    private function rollSpot(PuttContext $context): array
    {
        return [
            'distance' => self::LADDER[array_rand(self::LADDER)],
            'position' => $this->rollPosition($context),
        ];
    }

    private function rollPosition(PuttContext $context): ClockPosition
    {
        if ($context === PuttContext::Inside && mt_rand(1, 100) <= self::INDOOR_FLAT_SHARE) {
            return ClockPosition::Flat;
        }

        $ring = ClockPosition::ring();

        return $ring[array_rand($ring)];
    }

    private function randomLocation(): string
    {
        $spots = ['Home green', 'Muni practice green', 'Country club', 'Range putting green', 'Backyard'];

        return $spots[array_rand($spots)];
    }

    /**
     * Demo data only makes sense inside the challenge window, so widen the window
     * backwards to cover it. Re-run ChallengeSeeder to restore the real dates.
     */
    private function alignChallengeTo(Carbon $startsOn): void
    {
        $challenge = Challenge::current();

        if ($challenge !== null && $startsOn->lessThan($challenge->start_date)) {
            $challenge->update(['start_date' => $startsOn->toDateString()]);
        }
    }
}
