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

    /**
     * The putts eligible for this challenge, before any goal narrows them further.
     * Deliberately read at query time, never stamped onto the putt, so challenges
     * stack and editing a filter recounts the past.
     *
     * @return HasMany<Putt, User>
     */
    public function eligiblePutts(): HasMany
    {
        $timezone = $this->user->timezone;
        $putterIds = $this->relationLoaded('putters') ? $this->putters->modelKeys() : $this->putters()->pluck('putters.id')->all();

        return $this->user->putts()
            ->where('hit_at', '>=', $this->starts_on->copy()->shiftTimezone($timezone)->startOfDay()->utc())
            ->when($this->ends_on, fn ($query) => $query->where('hit_at', '<=', $this->ends_on->copy()->shiftTimezone($timezone)->endOfDay()->utc()))
            ->when($putterIds !== [], fn ($query) => $query->whereIn('putter_id', $putterIds))
            ->when($this->contexts?->isNotEmpty(), fn ($query) => $query->whereIn('context', $this->contexts))
            ->when($this->surface_types?->isNotEmpty(), fn ($query) => $query->whereIn('surface_type', $this->surface_types))
            ->when($this->min_distance_ft !== null, fn ($query) => $query->where('distance_ft', '>=', $this->min_distance_ft))
            ->when($this->max_distance_ft !== null, fn ($query) => $query->where('distance_ft', '<=', $this->max_distance_ft));
    }

    /**
     * The eligibility filters as a line: "Spider X · Inside · Putting mat · 3–10 ft".
     */
    public function describeFilters(): string
    {
        $parts = [
            $this->putters->isEmpty() ? 'Any putter' : $this->putters->pluck('name')->implode(', '),
            $this->contexts?->isNotEmpty() ? $this->contexts->map->label()->implode(' or ') : 'Inside or outside',
        ];

        if ($this->surface_types?->isNotEmpty()) {
            $parts[] = $this->surface_types->map->label()->implode(', ');
        }

        if ($this->min_distance_ft !== null || $this->max_distance_ft !== null) {
            $parts[] = match (true) {
                $this->min_distance_ft !== null && $this->max_distance_ft !== null => sprintf('%d–%d ft', $this->min_distance_ft, $this->max_distance_ft),
                $this->min_distance_ft !== null => sprintf('%d ft+', $this->min_distance_ft),
                default => sprintf('up to %d ft', $this->max_distance_ft),
            };
        }

        return implode(' · ', $parts);
    }

    /**
     * The filters in the shape the phone checks a putt against before it syncs.
     *
     * @return array{putter_ids: array<int, int>, contexts: array<int, string>, surface_types: array<int, string>, min_distance_ft: int|null, max_distance_ft: int|null}
     */
    public function eligibility(): array
    {
        return [
            'putter_ids' => $this->putters->modelKeys(),
            'contexts' => $this->contexts?->map->value->values()->all() ?? [],
            'surface_types' => $this->surface_types?->map->value->values()->all() ?? [],
            'min_distance_ft' => $this->min_distance_ft,
            'max_distance_ft' => $this->max_distance_ft,
        ];
    }

    /**
     * Compared as calendar dates, never as instants: the player's "today" lives in
     * their time zone while the stored dates are bare dates, and comparing the two
     * as moments would shift a challenge a day either side of UTC.
     */
    public function hasStarted(Carbon $today): bool
    {
        return $today->toDateString() >= $this->starts_on->toDateString();
    }

    public function hasEnded(Carbon $today): bool
    {
        return $this->ends_on !== null && $today->toDateString() > $this->ends_on->toDateString();
    }

    public function isDrill(): bool
    {
        return $this->kind === ChallengeKind::Drill;
    }

    public function isActiveOn(Carbon $date): bool
    {
        return $this->archived_at === null && $this->hasStarted($date) && ! $this->hasEnded($date);
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
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $date->toDateString()));
    }
}
