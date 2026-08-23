<?php

namespace App\Actions;

use App\Enums\PuttContext;
use App\Enums\PuttResult;
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
     * speed_tendency: -1 always leaves short, +1 always runs past.
     * line_tendency:  -1 always misses left, +1 always misses right.
     *
     * @var array<string, array<string, float>>
     */
    public const PROFILES = [
        'lag' => ['skill' => 96, 'decay' => 3.2, 'speed_tendency' => -0.8, 'line_tendency' => 0.05, 'speed_share' => 0.7],
        'charger' => ['skill' => 98, 'decay' => 3.0, 'speed_tendency' => 0.7, 'line_tendency' => -0.05, 'speed_share' => 0.68],
        'puller' => ['skill' => 95, 'decay' => 3.1, 'speed_tendency' => -0.1, 'line_tendency' => -0.75, 'speed_share' => 0.32],
        'pusher' => ['skill' => 95, 'decay' => 3.1, 'speed_tendency' => 0.1, 'line_tendency' => 0.75, 'speed_share' => 0.32],
        'elite' => ['skill' => 102, 'decay' => 2.1, 'speed_tendency' => 0.15, 'line_tendency' => 0.0, 'speed_share' => 0.5],
        'struggling' => ['skill' => 88, 'decay' => 4.2, 'speed_tendency' => -0.5, 'line_tendency' => -0.3, 'speed_share' => 0.55],
    ];

    /**
     * Wipe existing putts and generate a fresh random dataset.
     *
     * @return array<string, mixed> a summary of what was generated
     */
    public function execute(
        int $days = 14,
        ?string $profileName = null,
        bool $alignChallengeWindow = true,
    ): array {
        $profile = $this->resolveProfile($profileName);

        Putt::query()->delete();
        PuttingSession::query()->delete();

        $endsOn = Carbon::today();
        $startsOn = $endsOn->copy()->subDays($days - 1);

        if ($alignChallengeWindow) {
            $this->alignChallengeTo($startsOn);
        }

        $rows = [];
        $sessionCount = 0;

        for ($day = 0; $day < $days; $day++) {
            $date = $startsOn->copy()->addDays($day);

            // A rest day now and then, so the pace chart has a realistic shape.
            if (mt_rand(1, 100) <= 12) {
                continue;
            }

            foreach ($this->sessionPlanFor($date) as [$context, $startedAt, $volume]) {
                $session = PuttingSession::query()->create([
                    'context' => $context,
                    'location' => $context === PuttContext::Outside ? $this->randomLocation() : null,
                    'surface' => $context === PuttContext::Outside ? 'practice green' : 'carpet',
                    'started_at' => $startedAt,
                ]);

                $sessionCount++;

                for ($i = 0; $i < $volume; $i++) {
                    $rows[] = $this->buildPutt($session, $context, $startedAt->copy()->addSeconds($i * 35), $profile);
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Putt::query()->insert($chunk);
        }

        return [
            'profile' => $profile['name'],
            'putts' => count($rows),
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
            'skill' => mt_rand(880, 1010) / 10,
            'decay' => mt_rand(20, 45) / 10,
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
        ];
    }

    /**
     * @return array<int, array{0: PuttContext, 1: Carbon, 2: int}>
     */
    private function sessionPlanFor(Carbon $date): array
    {
        $plan = [[PuttContext::Inside, $date->copy()->setTime(mt_rand(18, 21), mt_rand(0, 50)), mt_rand(25, 80)]];

        // Outside practice only happens on some days.
        if (mt_rand(1, 100) <= 55) {
            $plan[] = [PuttContext::Outside, $date->copy()->setTime(mt_rand(8, 16), mt_rand(0, 50)), mt_rand(10, 35)];
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function buildPutt(PuttingSession $session, PuttContext $context, Carbon $hitAt, array $profile): array
    {
        $distance = self::LADDER[array_rand(self::LADDER)];
        $result = $this->rollResult($distance, $context, $profile);

        return [
            'uuid' => (string) Str::uuid(),
            'putting_session_id' => $session->id,
            'distance_ft' => $distance,
            'result' => $result->value,
            'context' => $context->value,
            'slope' => mt_rand(1, 100) <= 40 ? $this->randomSlope() : null,
            'break_direction' => null,
            'notes' => null,
            'hit_at' => $hitAt->toDateTimeString(),
            'created_at' => $hitAt->toDateTimeString(),
            'updated_at' => $hitAt->toDateTimeString(),
        ];
    }

    /** @param array<string, mixed> $profile */
    private function rollResult(int $distance, PuttContext $context, array $profile): PuttResult
    {
        $makeChance = $profile['skill'] - ($distance * $profile['decay']);

        if ($context === PuttContext::Outside) {
            $makeChance -= $profile['outdoor_penalty'];
        }

        $makeChance = max(2.0, min(97.0, $makeChance));
        $roll = mt_rand(0, 10000) / 100;

        if ($roll < $makeChance) {
            return PuttResult::Sunk;
        }

        if ($roll < $makeChance + $profile['lip_out_rate']) {
            return PuttResult::LipOut;
        }

        // Longer putts fail on speed more often than on line.
        $speedShare = min(0.9, $profile['speed_share'] + ($distance * 0.012));

        if (mt_rand(0, 100) / 100 < $speedShare) {
            $shortChance = 0.5 - ($profile['speed_tendency'] * 0.42);

            return mt_rand(0, 100) / 100 < $shortChance ? PuttResult::MissShort : PuttResult::MissLong;
        }

        $leftChance = 0.5 - ($profile['line_tendency'] * 0.42);

        return mt_rand(0, 100) / 100 < $leftChance ? PuttResult::MissLeft : PuttResult::MissRight;
    }

    private function randomSlope(): string
    {
        return ['uphill', 'downhill', 'flat'][array_rand(['uphill', 'downhill', 'flat'])];
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
