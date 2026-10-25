<?php

namespace App\Policies;

use App\Models\KnowledgeAttachment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class KnowledgeAttachmentPolicy
{
    /**
     * Access follows the subject the attachment belongs to.
     */
    public function view(User $user, KnowledgeAttachment $attachment): Response
    {
        return $attachment->subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, KnowledgeAttachment $attachment): Response
    {
        return $this->view($user, $attachment);
    }

    public function delete(User $user, KnowledgeAttachment $attachment): Response
    {
        return $this->view($user, $attachment);
    }
}
