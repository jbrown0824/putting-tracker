<?php

namespace App\Policies;

use App\Models\Putter;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PutterPolicy
{
    /**
     * Someone else's putter is reported as missing rather than forbidden, so ids
     * reveal nothing about other players.
     */
    public function update(User $user, Putter $putter): Response
    {
        return $putter->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Putter $putter): Response
    {
        return $this->update($user, $putter);
    }
}
