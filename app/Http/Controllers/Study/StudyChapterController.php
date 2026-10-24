<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StudyChapterRequest;
use App\Models\StudyChapter;
use App\Models\StudySubject;
use App\Services\Study\StudyChapterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class StudyChapterController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly StudyChapterService $chapters)
    {
        //
    }

    public function store(StudyChapterRequest $request, StudySubject $subject): RedirectResponse
    {
        $this->chapters->create($subject, (string) $request->validated('title'));

        $this->toast('Capítulo criado.');

        return back();
    }

    public function update(StudyChapterRequest $request, StudyChapter $chapter): RedirectResponse
    {
        $this->chapters->rename($chapter, (string) $request->validated('title'));

        $this->toast('Capítulo renomeado.');

        return back();
    }

    public function destroy(StudyChapter $chapter): RedirectResponse
    {
        Gate::authorize('delete', $chapter);

        $this->chapters->delete($chapter);

        $this->toast('Capítulo excluído. As páginas dele ficaram sem capítulo.');

        return back();
    }
}
