<?php

namespace App\Models;

use App\Enums\ClockPosition;
use Database\Factories\ChallengeStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeStep extends Model
{
    /** @use HasFactory<ChallengeStepFactory> */
    use HasFactory;

    protected $fillable = [
        'sort_order',
        'distance_ft',
        'clock_position',
        'makes_required',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'distance_ft' => 'integer',
            'clock_position' => ClockPosition::class,
            'makes_required' => 'integer',
        ];
    }

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
