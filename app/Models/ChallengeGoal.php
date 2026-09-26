<?php

namespace App\Models;

use App\Enums\GoalMetric;
use App\Enums\GoalPeriod;
use App\Enums\PuttContext;
use Database\Factories\ChallengeGoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeGoal extends Model
{
    /** @use HasFactory<ChallengeGoalFactory> */
    use HasFactory;

    protected $fillable = [
        'metric',
        'period',
        'target',
        'context',
        'min_distance_ft',
        'max_distance_ft',
        'min_attempts',
        'periods_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'metric' => GoalMetric::class,
            'period' => GoalPeriod::class,
            'target' => 'integer',
            'context' => PuttContext::class,
            'min_distance_ft' => 'integer',
            'max_distance_ft' => 'integer',
            'min_attempts' => 'integer',
            'periods_required' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * The goal as a sentence: "100 putts a day", "500 makes outside from 15 ft+",
     * "60% make rate from 6–10 ft (min 300 putts)".
     */
    public function describe(): string
    {
        $what = match ($this->metric) {
            GoalMetric::Attempts => sprintf('%s putts', number_format($this->target)),
            GoalMetric::Makes => sprintf('%s makes', number_format($this->target)),
            GoalMetric::MakePercent => sprintf('%d%% make rate', $this->target),
            GoalMetric::MakeStreak => sprintf('%d makes in a row', $this->target),
            GoalMetric::DaysPractised => sprintf('Practise %d days', $this->target),
            GoalMetric::DrillRunsCompleted => sprintf('Finish the drill %d %s', $this->target, $this->target === 1 ? 'time' : 'times'),
        };

        $parts = array_filter([
            $what,
            $this->context?->label() !== null ? strtolower($this->context->label()) : null,
            $this->describeDistance(),
            match ($this->period) {
                GoalPeriod::Daily => 'a day',
                GoalPeriod::Weekly => 'a week',
                GoalPeriod::Total => null,
            },
        ]);

        $sentence = implode(' ', $parts);

        if ($this->metric === GoalMetric::MakePercent && $this->min_attempts) {
            $sentence .= sprintf(' (min %s putts)', number_format($this->min_attempts));
        }

        if ($this->periods_required !== null && $this->period !== GoalPeriod::Total) {
            $sentence .= sprintf(', %d %s', $this->periods_required, $this->period === GoalPeriod::Daily ? 'days' : 'weeks');
        }

        return $sentence;
    }

    private function describeDistance(): ?string
    {
        return match (true) {
            $this->min_distance_ft !== null && $this->max_distance_ft !== null => sprintf('from %d–%d ft', $this->min_distance_ft, $this->max_distance_ft),
            $this->min_distance_ft !== null => sprintf('from %d ft+', $this->min_distance_ft),
            $this->max_distance_ft !== null => sprintf('inside %d ft', $this->max_distance_ft),
            default => null,
        };
    }

    /**
     * Whether a putt falls inside this goal's own narrowing, on top of the
     * challenge's eligibility filters.
     *
     * @param  array{context: string, distance_ft: int}  $putt
     */
    public function covers(array $putt): bool
    {
        return ($this->context === null || $this->context->value === $putt['context'])
            && ($this->min_distance_ft === null || $putt['distance_ft'] >= $this->min_distance_ft)
            && ($this->max_distance_ft === null || $putt['distance_ft'] <= $this->max_distance_ft);
    }
}
