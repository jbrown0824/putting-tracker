<?php

namespace App\Models;

use App\Enums\PuttContext;
use Database\Factories\PuttingSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PuttingSession extends Model
{
    /** @use HasFactory<PuttingSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'context',
        'location',
        'surface',
        'notes',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => PuttContext::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return HasMany<Putt, $this> */
    public function putts(): HasMany
    {
        return $this->hasMany(Putt::class);
    }
}
