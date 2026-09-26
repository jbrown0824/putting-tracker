<?php

namespace App\Models;

use App\Enums\PutterHeadType;
use Database\Factories\PutterFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Putter extends Model
{
    /** @use HasFactory<PutterFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'head_type',
        'brand',
        'model',
        'length_in',
        'notes',
        'is_default',
        'sort_order',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'head_type' => PutterHeadType::class,
            'length_in' => 'float',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Putt, $this> */
    public function putts(): HasMany
    {
        return $this->hasMany(Putt::class);
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Brand and model when known, otherwise the head type — the line under the name.
     */
    public function description(): string
    {
        $makeAndModel = trim(implode(' ', array_filter([$this->brand, $this->model])));

        return $makeAndModel !== '' ? $makeAndModel : $this->head_type->label();
    }

    /** @param Builder<Putter> $query */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /** @param Builder<Putter> $query */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
