<?php

namespace App\Models;

use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use App\Enums\SurfaceType;
use Database\Factories\PuttFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Putt extends Model
{
    /** @use HasFactory<PuttFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'putting_session_id',
        'putter_id',
        'distance_ft',
        'result',
        'miss_cause',
        'context',
        'surface_type',
        'slope',
        'clock_position',
        'challenge_run_id',
        'drill_step',
        'notes',
        'hit_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => PuttResult::class,
            'miss_cause' => LineMissCause::class,
            'context' => PuttContext::class,
            'surface_type' => SurfaceType::class,
            'slope' => PuttSlope::class,
            'clock_position' => ClockPosition::class,
            'distance_ft' => 'integer',
            'drill_step' => 'integer',
            'hit_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PuttingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PuttingSession::class, 'putting_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Putter, $this> */
    public function putter(): BelongsTo
    {
        return $this->belongsTo(Putter::class);
    }

    /** @return BelongsTo<ChallengeRun, $this> */
    public function challengeRun(): BelongsTo
    {
        return $this->belongsTo(ChallengeRun::class);
    }

    /** @param Builder<Putt> $query */
    #[Scope]
    protected function sunk(Builder $query): Builder
    {
        return $query->where('result', PuttResult::Sunk);
    }

    /** @param Builder<Putt> $query */
    #[Scope]
    protected function outside(Builder $query): Builder
    {
        return $query->where('context', PuttContext::Outside);
    }

    /** @param Builder<Putt> $query */
    #[Scope]
    protected function inside(Builder $query): Builder
    {
        return $query->where('context', PuttContext::Inside);
    }
}
