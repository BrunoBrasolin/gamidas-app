<?php

namespace App\Policies;

use App\Models\StudyAttachment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StudyAttachmentPolicy
{
    /**
     * Access follows the subject the attachment belongs to.
     */
    public function view(User $user, StudyAttachment $attachment): Response
    {
        return $attachment->subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, StudyAttachment $attachment): Response
    {
        return $this->view($user, $attachment);
    }

    public function delete(User $user, StudyAttachment $attachment): Response
    {
        return $this->view($user, $attachment);
    }
}
