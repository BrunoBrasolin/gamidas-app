<?php

namespace App\Http\Controllers\Study;

use App\Enums\StudyAttachmentKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StudyPresenter;
use App\Http\Requests\Study\StudyPageContentRequest;
use App\Http\Requests\Study\StudyPageMoveRequest;
use App\Http\Requests\Study\StudyPageRequest;
use App\Http\Requests\Study\StudyPageStatusRequest;
use App\Http\Requests\Study\StudyPageTitleRequest;
use App\Http\Requests\Study\StudyReadingRequest;
use App\Models\StudyChapter;
use App\Models\StudyPage;
use App\Models\StudySubject;
use App\Services\Study\StaleRevisionException;
use App\Services\Study\StudyPageService;
use App\Support\StudyContent;
use App\Support\StudyUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StudyPageController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly StudyPageService $pages)
    {
        //
    }

    /**
     * Create a page and open it in the editor.
     */
    public function store(StudyPageRequest $request, StudySubject $subject): RedirectResponse
    {
        $page = $this->pages->create($subject, (string) $request->validated('title'), $request->chapter($subject));

        return to_route('study.pages.edit', $page);
    }

    /**
     * The reading view.
     */
    public function show(StudyPage $page): Response
    {
        Gate::authorize('view', $page);

        $position = $page->reading_position;
        $this->pages->recordReading($page);

        $subject = $page->subject;
        $toc = $this->tableOfContents($subject);
        $index = $toc->search(fn (StudyPage $entry) => $entry->id === $page->id);
        $neighbor = fn (?StudyPage $entry) => $entry === null ? null : ['id' => $entry->id, 'title' => $entry->title];

        return Inertia::render('study/read', [
            'subject' => StudyPresenter::subject($subject),
            'page' => [
                ...StudyPresenter::pageSummary($page),
                'content' => $page->content ?? StudyContent::empty(),
                'chapter' => $page->chapter?->title,
                'reading_position' => $position,
            ],
            'attachments' => StudyPresenter::attachmentMap($page->attachments()->whereNull('detached_at')->get()),
            'previous' => $index === false ? null : $neighbor($toc->get($index - 1)),
            'next' => $index === false ? null : $neighbor($toc->get($index + 1)),
            'chapters' => $subject->chapters->map(fn (StudyChapter $chapter) => StudyPresenter::chapter($chapter))->values(),
            'toc' => $toc->map(fn (StudyPage $entry) => StudyPresenter::pageSummary($entry))->values(),
        ]);
    }

    /**
     * The editor.
     */
    public function edit(StudyPage $page): Response
    {
        Gate::authorize('update', $page);

        return Inertia::render('study/edit', [
            'subject' => StudyPresenter::subject($page->subject),
            'page' => [
                ...StudyPresenter::pageSummary($page),
                'content' => $page->content ?? StudyContent::empty(),
                'chapter' => $page->chapter?->title,
                'revision' => $page->revision,
            ],
            // Detached ones too, so undo can bring a removed block back.
            'attachments' => StudyPresenter::attachmentMap($page->attachments()->get()),
            'limits' => [
                'image_kb' => StudyUpload::maxKilobytes(StudyAttachmentKind::Image),
                'pdf_kb' => StudyUpload::maxKilobytes(StudyAttachmentKind::Pdf),
            ],
        ]);
    }

    /**
     * Rename from the table of contents.
     */
    public function update(StudyPageTitleRequest $request, StudyPage $page): RedirectResponse
    {
        $this->pages->rename($page, (string) $request->validated('title'));

        $this->toast('Página renomeada.');

        return back();
    }

    /**
     * Editor autosave (JSON). Answers 409 with the current revision when the
     * page was saved elsewhere meanwhile.
     */
    public function content(StudyPageContentRequest $request, StudyPage $page): JsonResponse
    {
        try {
            $saved = $this->pages->saveContent(
                $page,
                (string) $request->validated('title'),
                $request->content(),
                (int) $request->validated('revision'),
                (bool) $request->validated('force'),
            );
        } catch (StaleRevisionException $exception) {
            return response()->json([
                'message' => 'Esta página foi alterada em outro lugar.',
                'revision' => $exception->page->revision,
                'updated_at' => $exception->page->updated_at?->toIso8601String(),
            ], 409);
        }

        return response()->json([
            'revision' => $saved->revision,
            'updated_at' => $saved->updated_at?->toIso8601String(),
        ]);
    }

    public function status(StudyPageStatusRequest $request, StudyPage $page): RedirectResponse
    {
        $this->pages->setStatus($page, $request->status());

        return back();
    }

    public function bookmark(StudyPage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->bookmark($page, true);

        $this->toast('Página marcada.');

        return back();
    }

    public function unbookmark(StudyPage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->bookmark($page, false);

        $this->toast('Marcador removido.');

        return back();
    }

    public function duplicate(StudyPage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $copy = $this->pages->duplicate($page);

        $this->toast('Página duplicada.');

        return to_route('study.pages.edit', $copy);
    }

    public function move(StudyPageMoveRequest $request, StudyPage $page): RedirectResponse
    {
        $subject = $request->targetSubject();

        $this->pages->move($page, $subject, $request->chapter($subject));

        $this->toast('Página movida.');

        return back();
    }

    public function archive(StudyPage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->archive($page);

        $this->toast('Página arquivada.');

        return back();
    }

    public function restore(StudyPage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->restore($page);

        $this->toast('Página restaurada.');

        return back();
    }

    public function destroy(StudyPage $page): RedirectResponse
    {
        Gate::authorize('delete', $page);

        $subject = $page->subject;
        $this->pages->delete($page);

        $this->toast('Página excluída.');

        return to_route('study.subjects.show', $subject);
    }

    /**
     * Where the reader was left (JSON, sent while scrolling).
     */
    public function reading(StudyReadingRequest $request, StudyPage $page): JsonResponse
    {
        $this->pages->recordReading($page, (int) $request->validated('position'));

        return response()->json(['ok' => true]);
    }

    /**
     * The pages of a subject in reading order: chapter by chapter, then the
     * pages without chapter. Archived pages are left out.
     *
     * @return Collection<int, StudyPage>
     */
    private function tableOfContents(StudySubject $subject): Collection
    {
        $chapterOrder = $subject->chapters->pluck('id')->flip();

        return $subject->pages()
            ->whereNull('archived_at')
            ->get()
            ->sortBy([
                fn (StudyPage $a, StudyPage $b) => ($chapterOrder[$a->chapter_id] ?? PHP_INT_MAX) <=> ($chapterOrder[$b->chapter_id] ?? PHP_INT_MAX),
                fn (StudyPage $a, StudyPage $b) => $a->sort_order <=> $b->sort_order,
                fn (StudyPage $a, StudyPage $b) => $a->id <=> $b->id,
            ])
            ->values()
            ->toBase();
    }
}
