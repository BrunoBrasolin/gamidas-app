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

    /**
     * Only subject files are deleted on their own: page files leave with
     * their block in the editor, covers through the subject form.
     */
    public function delete(User $user, KnowledgeAttachment $attachment): Response
    {
        $view = $this->view($user, $attachment);

        if ($view->denied() || $attachment->is_subject_file) {
            return $view;
        }

        return Response::deny('Este arquivo está dentro de uma página: remova-o pelo editor da página.');
    }
}
