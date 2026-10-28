<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Conversations are private: only their owner may see them, admins included.
 * Another user's conversation is reported as not found, so its existence is
 * not revealed.
 */
class ConversationPolicy
{
    /**
     * Determine whether the user can view the conversation and its messages.
     */
    public function view(User $user, Conversation $conversation): Response
    {
        return $conversation->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
