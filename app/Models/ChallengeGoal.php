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
}
