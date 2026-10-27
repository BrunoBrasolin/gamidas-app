<?php

namespace App\Http\Controllers\Knowledge;

use App\Enums\KnowledgeAttachmentKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\KnowledgePresenter;
use App\Http\Requests\Knowledge\KnowledgeArrangeRequest;
use App\Http\Requests\Knowledge\KnowledgeSubjectOrderRequest;
use App\Http\Requests\Knowledge\KnowledgeSubjectRequest;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use App\Services\Knowledge\KnowledgePageService;
use App\Services\Knowledge\KnowledgeSubjectService;
use App\Support\KnowledgeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeSubjectController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(
        private readonly KnowledgeSubjectService $subjects,
        private readonly KnowledgePageService $pages,
    ) {
        //
    }

    /**
     * The table of contents of a subject.
     */
    public function show(Request $request, KnowledgeSubject $subject): Response
    {
        Gate::authorize('view', $subject);

        $this->subjects->markOpened($subject);
        $subject->loadCount(KnowledgePresenter::subjectCounts());

        $pages = $subject->pages()
            ->withCount(['attachments' => fn ($query) => $query->whereNull('detached_at')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $continue = $pages->whereNull('archived_at')->whereNotNull('last_read_at')->sortByDesc('last_read_at')->first();

        return Inertia::render('knowledge/subject', [
            'subject' => KnowledgePresenter::subject($subject),
            'categories' => $subject->categories->map(fn (KnowledgeCategory $category) => KnowledgePresenter::category($category))->values(),
            'pages' => $pages->map(fn (KnowledgePage $page) => KnowledgePresenter::pageSummary($page))->values(),
            'continue_page' => $continue === null ? null : ['id' => $continue->id, 'title' => $continue->title],
            // Files attached to the subject itself ("Anexos").
            'files' => $subject->files()->get()->map(fn (KnowledgeAttachment $file) => [
                ...KnowledgePresenter::attachment($file),
                'added_at' => $file->created_at?->toIso8601String(),
            ])->values(),
            'limits' => [
                'image_kb' => KnowledgeUpload::maxKilobytes(KnowledgeAttachmentKind::Image),
                'file_kb' => KnowledgeUpload::maxKilobytes(KnowledgeAttachmentKind::Pdf),
            ],
            // Where a page can be moved to.
            'destinations' => $this->user($request)->knowledgeSubjects()
                ->whereNull('archived_at')
                ->with('categories')
                ->orderBy('title')
                ->get()
                ->map(fn (KnowledgeSubject $other) => [
                    'id' => $other->id,
                    'title' => $other->title,
                    'categories' => $other->categories->map(fn (KnowledgeCategory $category) => KnowledgePresenter::category($category))->values(),
                ])
                ->values(),
        ]);
    }

    public function store(KnowledgeSubjectRequest $request): RedirectResponse
    {
        $subject = $this->subjects->create($this->user($request), $request->subject(), $request->cover());

        $this->toast('Matéria criada.');

        return to_route('knowledge.subjects.show', $subject);
    }

    public function update(KnowledgeSubjectRequest $request, KnowledgeSubject $subject): RedirectResponse
    {
        $this->subjects->update($subject, $request->subject(), $request->cover(), (bool) $request->validated('remove_cover'));

        $this->toast('Matéria atualizada.');

        return back();
    }

    public function destroy(KnowledgeSubject $subject): RedirectResponse
    {
        Gate::authorize('delete', $subject);

        $this->subjects->delete($subject);

        $this->toast('Matéria excluída.');

        return to_route('knowledge.index');
    }

    public function archive(KnowledgeSubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->archive($subject);

        $this->toast('Matéria arquivada.');

        return back();
    }

    public function restore(KnowledgeSubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->restore($subject);

        $this->toast('Matéria restaurada.');

        return back();
    }

    public function pin(KnowledgeSubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->pin($subject, true);

        return back();
    }

    public function unpin(KnowledgeSubject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $this->subjects->pin($subject, false);

        return back();
    }

    /**
     * Save the manual order of the bookshelf.
     */
    public function order(KnowledgeSubjectOrderRequest $request): RedirectResponse
    {
        $this->subjects->reorder($this->user($request), $request->ids());

        return back();
    }

    /**
     * Save the order of categories and pages of the table of contents.
     */
    public function arrange(KnowledgeArrangeRequest $request, KnowledgeSubject $subject): RedirectResponse
    {
        $this->pages->arrange($subject, $request->categoryIds(), $request->pages());

        return back();
    }
}
