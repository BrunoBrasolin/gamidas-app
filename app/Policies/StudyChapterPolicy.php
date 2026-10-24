<?php

namespace App\Policies;

use App\Models\StudyChapter;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StudyChapterPolicy
{
    /**
     * Access follows the subject the chapter belongs to.
     */
    public function view(User $user, StudyChapter $chapter): Response
    {
        return $chapter->subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, StudyChapter $chapter): Response
    {
        return $this->view($user, $chapter);
    }

    public function delete(User $user, StudyChapter $chapter): Response
    {
        return $this->view($user, $chapter);
    }
}
