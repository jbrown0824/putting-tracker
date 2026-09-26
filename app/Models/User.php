<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PutterHeadType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Putter, $this> */
    public function putters(): HasMany
    {
        return $this->hasMany(Putter::class);
    }

    /** @return HasMany<PuttingSession, $this> */
    public function puttingSessions(): HasMany
    {
        return $this->hasMany(PuttingSession::class);
    }

    /** @return HasMany<Putt, $this> */
    public function putts(): HasMany
    {
        return $this->hasMany(Putt::class);
    }

    /** @return HasMany<Challenge, $this> */
    public function challenges(): HasMany
    {
        return $this->hasMany(Challenge::class);
    }

    /** @return HasMany<ChallengeRun, $this> */
    public function challengeRuns(): HasMany
    {
        return $this->hasMany(ChallengeRun::class);
    }

    /**
     * The putter a putt is credited to when the phone does not say which one hit
     * it, which happens whenever a queued putt refers to a putter that no longer
     * exists or was posted by a bundle predating the field.
     *
     * Never null: a player with no putters at all gets one created, so a putt is
     * never rejected for want of somewhere to file it.
     */
    public function defaultPutter(): Putter
    {
        return $this->putters()->active()->orderByDesc('is_default')->ordered()->first()
            ?? $this->putters()->create([
                'name' => 'My putter',
                'head_type' => PutterHeadType::Other,
                'is_default' => true,
            ]);
    }
}
