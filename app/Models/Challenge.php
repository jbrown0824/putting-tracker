<?php

namespace App\Models;

use Database\Factories\ChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Challenge extends Model
{
    /** @use HasFactory<ChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'target_total',
        'target_outside_min',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'target_total' => 'integer',
            'target_outside_min' => 'integer',
        ];
    }

    /**
     * The challenge being tracked. This app is single-user, so there is only ever one.
     */
    public static function current(): ?self
    {
        return static::query()->latest('start_date')->first();
    }

    public function totalDays(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Days left including today. Zero once the end date has passed.
     */
    public function daysRemaining(): int
    {
        $today = Carbon::today();

        if ($today->greaterThan($this->end_date)) {
            return 0;
        }

        $from = $today->lessThan($this->start_date) ? $this->start_date : $today;

        return (int) $from->diffInDays($this->end_date) + 1;
    }
}
