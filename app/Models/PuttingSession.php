<?php

namespace App\Models;

use App\Enums\PuttContext;
use App\Enums\SurfaceType;
use Database\Factories\PuttingSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PuttingSession extends Model
{
    /** @use HasFactory<PuttingSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'putter_id',
        'context',
        'surface_type',
        'location',
        'notes',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => PuttContext::class,
            'surface_type' => SurfaceType::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
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

    /** @return HasMany<Putt, $this> */
    public function putts(): HasMany
    {
        return $this->hasMany(Putt::class);
    }

    /**
     * "Inside" or "Outside · Practice green", for anywhere a session is summarised.
     */
    public function whereLabel(): string
    {
        return $this->surface_type !== null
            ? sprintf('%s · %s', $this->context->label(), $this->surface_type->label())
            : $this->context->label();
    }
}
