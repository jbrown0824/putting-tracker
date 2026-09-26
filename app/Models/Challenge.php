<?php

namespace App\Models;

use App\Enums\ChallengeKind;
use App\Enums\DrillMissRule;
use App\Enums\DrillOrder;
use App\Enums\PuttContext;
use App\Enums\SurfaceType;
use Database\Factories\ChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Challenge extends Model
{
    /** @use HasFactory<ChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'kind',
        'starts_on',
        'ends_on',
        'contexts',
        'surface_types',
        'min_distance_ft',
        'max_distance_ft',
        'drill_on_miss',
        'drill_order',
        'drill_makes_required',
        'drill_rounds',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ChallengeKind::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'contexts' => AsEnumCollection::of(PuttContext::class),
            'surface_types' => AsEnumCollection::of(SurfaceType::class),
            'min_distance_ft' => 'integer',
            'max_distance_ft' => 'integer',
            'drill_on_miss' => DrillMissRule::class,
            'drill_order' => DrillOrder::class,
            'drill_makes_required' => 'integer',
            'drill_rounds' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The putters whose putts count. Empty means any putter does.
     *
     * @return BelongsToMany<Putter, $this>
     */
    public function putters(): BelongsToMany
    {
        return $this->belongsToMany(Putter::class);
    }

    /** @return HasMany<ChallengeGoal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(ChallengeGoal::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ChallengeStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ChallengeStep::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ChallengeRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ChallengeRun::class);
    }

    public function isDrill(): bool
    {
        return $this->kind === ChallengeKind::Drill;
    }

    public function isActiveOn(Carbon $date): bool
    {
        return $this->archived_at === null
            && $date->greaterThanOrEqualTo($this->starts_on)
            && ($this->ends_on === null || $date->lessThanOrEqualTo($this->ends_on));
    }

    /**
     * Challenges running on the given day, in the player's own calendar.
     *
     * @param  Builder<Challenge>  $query
     */
    #[Scope]
    protected function activeOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->whereNull('archived_at')
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $date));
    }
}
