<?php

namespace App\Policies;

use App\Models\PuttingSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PuttingSessionPolicy
{
    /**
     * Someone else's session is reported as missing rather than forbidden, so ids
     * reveal nothing about other players.
     */
    public function view(User $user, PuttingSession $session): Response
    {
        return $session->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, PuttingSession $session): Response
    {
        return $this->view($user, $session);
    }
}
