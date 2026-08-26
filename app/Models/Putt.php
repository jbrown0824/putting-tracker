<?php

namespace App\Models;

use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
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
        'distance_ft',
        'result',
        'miss_cause',
        'context',
        'putter',
        'slope',
        'break_direction',
        'notes',
        'hit_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => PuttResult::class,
            'miss_cause' => LineMissCause::class,
            'context' => PuttContext::class,
            'putter' => Putter::class,
            'slope' => PuttSlope::class,
            'distance_ft' => 'integer',
            'hit_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PuttingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PuttingSession::class, 'putting_session_id');
    }

    /** @param Builder<Putt> $query */
    #[Scope]
    protected function forPutter(Builder $query, Putter $putter): Builder
    {
        return $query->where('putter', $putter);
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
