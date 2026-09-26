<?php

namespace App\Models;

use Database\Factories\ChallengeRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChallengeRun extends Model
{
    /** @use HasFactory<ChallengeRunFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'challenge_id',
        'started_at',
        'completed_at',
        'abandoned_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /** @return HasMany<Putt, $this> */
    public function putts(): HasMany
    {
        return $this->hasMany(Putt::class);
    }
}
