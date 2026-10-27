<?php

namespace App\Http\Controllers\Knowledge;

use App\Enums\KnowledgeAttachmentKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\KnowledgePresenter;
use App\Http\Requests\Knowledge\KnowledgeFilePageRequest;
use App\Http\Requests\Knowledge\KnowledgePageContentRequest;
use App\Http\Requests\Knowledge\KnowledgePageMoveRequest;
use App\Http\Requests\Knowledge\KnowledgePageRequest;
use App\Http\Requests\Knowledge\KnowledgePageStatusRequest;
use App\Http\Requests\Knowledge\KnowledgePageTitleRequest;
use App\Http\Requests\Knowledge\KnowledgeReadingRequest;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use App\Services\Knowledge\KnowledgePageService;
use App\Services\Knowledge\StaleRevisionException;
use App\Support\KnowledgeCategoryTree;
use App\Support\KnowledgeContent;
use App\Support\KnowledgeUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgePageController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly KnowledgePageService $pages)
    {
        //
    }

    /**
     * Create a page and open it in the editor.
     */
    public function store(KnowledgePageRequest $request, KnowledgeSubject $subject): RedirectResponse
    {
        $page = $this->pages->create($subject, (string) $request->validated('title'), $request->category($subject));

        return to_route('knowledge.pages.edit', $page);
    }

    /**
     * Create a page holding an uploaded file and open it in the reader.
     */
    public function upload(KnowledgeFilePageRequest $request, KnowledgeSubject $subject): RedirectResponse
    {
        $page = $this->pages->createFromFile($subject, $request->category($subject), $request->upload(), $request->info());

        $this->toast('Arquivo enviado.');

        return to_route('knowledge.pages.show', $page);
    }

    /**
     * The reading view.
     */
    public function show(KnowledgePage $page): Response
    {
        Gate::authorize('view', $page);

        $position = $page->reading_position;
        $this->pages->recordReading($page);

        $subject = $page->subject;
        $toc = $this->tableOfContents($subject);
        $index = $toc->search(fn (KnowledgePage $entry) => $entry->id === $page->id);
        $neighbor = fn (?KnowledgePage $entry) => $entry === null ? null : ['id' => $entry->id, 'title' => $entry->title];

        return Inertia::render('knowledge/read', [
            'subject' => KnowledgePresenter::subject($subject),
            'page' => [
                ...KnowledgePresenter::pageSummary($page),
                'content' => $page->content ?? KnowledgeContent::empty(),
                'category' => KnowledgePresenter::categoryPath($page->subject->categories, $page->category_id),
                'reading_position' => $position,
            ],
            'attachments' => KnowledgePresenter::attachmentMap($page->attachments()->whereNull('detached_at')->get()),
            'previous' => $index === false ? null : $neighbor($toc->get($index - 1)),
            'next' => $index === false ? null : $neighbor($toc->get($index + 1)),
            'categories' => $subject->categories->map(fn (KnowledgeCategory $category) => KnowledgePresenter::category($category))->values(),
            'toc' => $toc->map(fn (KnowledgePage $entry) => KnowledgePresenter::pageSummary($entry))->values(),
        ]);
    }

    /**
     * The editor.
     */
    public function edit(KnowledgePage $page): Response
    {
        Gate::authorize('update', $page);

        return Inertia::render('knowledge/edit', [
            'subject' => KnowledgePresenter::subject($page->subject),
            'page' => [
                ...KnowledgePresenter::pageSummary($page),
                'content' => $page->content ?? KnowledgeContent::empty(),
                'category' => KnowledgePresenter::categoryPath($page->subject->categories, $page->category_id),
                'revision' => $page->revision,
            ],
            // Detached ones too, so undo can bring a removed block back.
            'attachments' => KnowledgePresenter::attachmentMap($page->attachments()->get()),
            'limits' => [
                'image_kb' => KnowledgeUpload::maxKilobytes(KnowledgeAttachmentKind::Image),
                'file_kb' => KnowledgeUpload::maxKilobytes(KnowledgeAttachmentKind::Pdf),
            ],
        ]);
    }

    /**
     * Rename from the table of contents.
     */
    public function update(KnowledgePageTitleRequest $request, KnowledgePage $page): RedirectResponse
    {
        $this->pages->rename($page, (string) $request->validated('title'));

        $this->toast('Página renomeada.');

        return back();
    }

    /**
     * Editor autosave (JSON). Answers 409 with the current revision when the
     * page was saved elsewhere meanwhile.
     */
    public function content(KnowledgePageContentRequest $request, KnowledgePage $page): JsonResponse
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

    public function status(KnowledgePageStatusRequest $request, KnowledgePage $page): RedirectResponse
    {
        $this->pages->setStatus($page, $request->status());

        return back();
    }

    public function bookmark(KnowledgePage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->bookmark($page, true);

        $this->toast('Página marcada.');

        return back();
    }

    public function unbookmark(KnowledgePage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->bookmark($page, false);

        $this->toast('Marcador removido.');

        return back();
    }

    public function duplicate(KnowledgePage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $copy = $this->pages->duplicate($page);

        $this->toast('Página duplicada.');

        return to_route('knowledge.pages.edit', $copy);
    }

    public function move(KnowledgePageMoveRequest $request, KnowledgePage $page): RedirectResponse
    {
        $subject = $request->targetSubject();

        $this->pages->move($page, $subject, $request->category($subject));

        $this->toast('Página movida.');

        return back();
    }

    public function archive(KnowledgePage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->archive($page);

        $this->toast('Página arquivada.');

        return back();
    }

    public function restore(KnowledgePage $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $this->pages->restore($page);

        $this->toast('Página restaurada.');

        return back();
    }

    public function destroy(KnowledgePage $page): RedirectResponse
    {
        Gate::authorize('delete', $page);

        $subject = $page->subject;
        $this->pages->delete($page);

        $this->toast('Página excluída.');

        return to_route('knowledge.subjects.show', $subject);
    }

    /**
     * Where the reader was left (JSON, sent while scrolling).
     */
    public function reading(KnowledgeReadingRequest $request, KnowledgePage $page): JsonResponse
    {
        $this->pages->recordReading($page, (int) $request->validated('position'));

        return response()->json(['ok' => true]);
    }

    /**
     * The pages of a subject in reading order: category by category (each one
     * followed by its subcategories), then the pages without category.
     * Archived pages are left out.
     *
     * @return Collection<int, KnowledgePage>
     */
    private function tableOfContents(KnowledgeSubject $subject): Collection
    {
        $categoryOrder = array_flip((new KnowledgeCategoryTree($subject->categories))->ordered());

        return $subject->pages()
            ->whereNull('archived_at')
            ->get()
            ->sortBy([
                fn (KnowledgePage $a, KnowledgePage $b) => ($categoryOrder[$a->category_id] ?? PHP_INT_MAX) <=> ($categoryOrder[$b->category_id] ?? PHP_INT_MAX),
                fn (KnowledgePage $a, KnowledgePage $b) => $a->sort_order <=> $b->sort_order,
                fn (KnowledgePage $a, KnowledgePage $b) => $a->id <=> $b->id,
            ])
            ->values()
            ->toBase();
    }
}
