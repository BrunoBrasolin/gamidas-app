<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StudyPresenter;
use App\Http\Requests\Study\StudyArrangeRequest;
use App\Http\Requests\Study\StudySubjectOrderRequest;
use App\Http\Requests\Study\StudySubjectRequest;
use App\Models\StudyChapter;
use App\Models\StudyPage;
use App\Models\StudySubject;
use App\Services\Study\StudyPageService;
use App\Services\Study\StudySubjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StudySubjectController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(
        private readonly StudySubjectService $subjects,
        private readonly StudyPageService $pages,
    ) {
        //
    }

    /**
     * The table of contents of a subject.
     */
    public function show(Request $request, StudySubject $subject): Response
    {
        Gate::authorize('view', $subject);

        $this->subjects->markOpened($subject);
        $subject->loadCount(StudyPresenter::subjectCounts());

        $pages = $subject->pages()
            ->withCount(['attachments' => fn ($query) => $query->whereNull('detached_at')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $continue = $pages->whereNull('archived_at')->whereNotNull('last_read_at')->sortByDesc('last_read_at')->first();

        return Inertia::render('study/subject', [
            'subject' => StudyPresenter::subject($subject),
            'chapters' => $subject->chapters->map(fn (StudyChapter $chapter) => StudyPresenter::chapter($chapter))->values(),
            'pages' => $pages->map(fn (StudyPage $page) => StudyPresenter::pageSummary($page))->values(),
            'continue_page' => $continue === null ? null : ['id' => $continue->id, 'title' => $continue->title],
            // Where a page can be moved to.
            'destinations' => $this->user($request)->studySubjects()
                ->whereNull('archived_at')
                ->with('chapters')
                ->orderBy('title')
                ->get()
                ->map(fn (StudySubject $other) => [
                    'id' => $other->id,
                    'title' => $other->title,
                    'chapters' => $other->chapters->map(fn (StudyChapter $chapter) => ['id' => $chapter->id, 'title' => $chapter->title])->values(),
                ])
                ->values(),
        ]);
    }

    public function store(StudySubjectRequest $request): RedirectResponse
    {
        $subject = $this->subjects->create($this->user($request), $request->subject(), $request->cover());

        $this->toast('Matéria criada.');

        return to_route('study.subjects.show', $subject);
    }

    public function update(StudySubjectRequest $request, StudySubject $subject): RedirectResponse
    {
        $this->subjects->update($subject, $request->subject(), $request->cover(), (bool) $request->validated('remove_cover'));

        $this->toast('Matéria atualizada.');

        return back();
    }

    public function destroy(StudySubject $subject): RedirectResponse
    {
        Gate::authorize('delete', $subject);

        $this->subjects->delete($subject);

        $this->toast('Matéria excluída.');

        return to_route('study.index');
    }

    public function archive(StudySubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->archive($subject);

        $this->toast('Matéria arquivada.');

        return back();
    }

    public function restore(StudySubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->restore($subject);

        $this->toast('Matéria restaurada.');

        return back();
    }

    public function pin(StudySubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->pin($subject, true);

        return back();
    }

    public function unpin(StudySubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->pin($subject, false);

        return back();
    }

    /**
     * Save the manual order of the bookshelf.
     */
    public function order(StudySubjectOrderRequest $request): RedirectResponse
    {
        $this->subjects->reorder($this->user($request), $request->ids());

        return back();
    }

    /**
     * Save the order of chapters and pages of the table of contents.
     */
    public function arrange(StudyArrangeRequest $request, StudySubject $subject): RedirectResponse
    {
        $this->pages->arrange($subject, $request->chapterIds(), $request->pages());

        return back();
    }
}
