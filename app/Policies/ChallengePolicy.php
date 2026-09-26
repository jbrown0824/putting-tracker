<?php

namespace App\Policies;

use App\Models\Challenge;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ChallengePolicy
{
    /**
     * Someone else's challenge is reported as missing rather than forbidden, so ids
     * reveal nothing about other players.
     */
    public function view(User $user, Challenge $challenge): Response
    {
        return $challenge->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Challenge $challenge): Response
    {
        return $this->view($user, $challenge);
    }

    public function delete(User $user, Challenge $challenge): Response
    {
        return $this->view($user, $challenge);
    }
}
