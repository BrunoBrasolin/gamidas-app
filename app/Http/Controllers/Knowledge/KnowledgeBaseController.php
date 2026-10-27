<?php

namespace App\Http\Controllers\Knowledge;

use App\Enums\KnowledgeAttachmentKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\KnowledgePresenter;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bookshelf of the user's knowledge base, its files and the search
 * across it.
 */
class KnowledgeBaseController extends Controller
{
    use InteractsWithHousehold;

    private const SEARCH_LIMIT = 50;

    /**
     * Every file of the knowledge base, newest first: those attached to a
     * subject and those placed in a page. Images in the text and covers are
     * left out.
     */
    public function files(Request $request): Response
    {
        $user = $this->user($request);

        $files = KnowledgeAttachment::query()
            ->whereHas('subject', fn (Builder $query) => $query->where('user_id', $user->id))
            ->whereNull('detached_at')
            ->where(fn (Builder $query) => $query
                ->where('is_subject_file', true)
                ->orWhere(fn (Builder $placed) => $placed->whereNotNull('page_id')->where('kind', '!=', KnowledgeAttachmentKind::Image->value)))
            ->with(['subject', 'page'])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('knowledge/files', [
            'files' => $files->map(fn (KnowledgeAttachment $file) => KnowledgePresenter::fileWithPlace($file))->values(),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $subjects = $user->knowledgeSubjects()
            ->withCount(KnowledgePresenter::subjectCounts())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $pages = fn () => KnowledgePage::query()
            ->whereHas('subject', fn (Builder $query) => $query->where('user_id', $user->id)->whereNull('archived_at'))
            ->whereNull('archived_at')
            ->with('subject');

        // The page last read in each subject, to "continue reading".
        $continue = $pages()
            ->whereNotNull('last_read_at')
            ->orderByDesc('last_read_at')
            ->get(['id', 'subject_id', 'title', 'last_read_at'])
            ->unique('subject_id')
            ->mapWithKeys(fn (KnowledgePage $page) => [$page->subject_id => ['id' => $page->id, 'title' => $page->title]]);

        return Inertia::render('knowledge/index', [
            'subjects' => $subjects
                ->map(fn (KnowledgeSubject $subject) => [
                    ...KnowledgePresenter::subject($subject),
                    'continue_page' => $continue[$subject->id] ?? null,
                ])
                ->values(),
            'recent_pages' => $pages()->whereNotNull('last_read_at')->orderByDesc('last_read_at')->limit(6)->get()
                ->map(fn (KnowledgePage $page) => KnowledgePresenter::pageWithSubject($page))->values(),
            'bookmarks' => $pages()->whereNotNull('bookmarked_at')->orderByDesc('bookmarked_at')->limit(30)->get()
                ->map(fn (KnowledgePage $page) => KnowledgePresenter::pageWithSubject($page))->values(),
        ]);
    }

    /**
     * Search subject titles and descriptions, category titles and page titles
     * and text, optionally within one subject. LIKE keeps it portable between
     * SQLite and MySQL (both match case-insensitively).
     */
    public function search(Request $request): Response
    {
        $user = $this->user($request);
        $query = Str::limit(trim((string) $request->query('q', '')), 100, '');
        $within = $request->integer('subject') > 0
            ? $user->knowledgeSubjects()->find($request->integer('subject'))
            : null;
        $results = ['subjects' => [], 'categories' => [], 'pages' => []];

        if (mb_strlen($query) >= 2) {
            $like = '%'.addcslashes($query, '\\%_').'%';
            $owned = fn (Builder $q) => $q->where('user_id', $user->id)->when($within, fn (Builder $q) => $q->whereKey($within?->id));

            $results['subjects'] = $within !== null ? [] : $user->knowledgeSubjects()
                ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like))
                ->withCount(KnowledgePresenter::subjectCounts())
                ->orderBy('title')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (KnowledgeSubject $subject) => KnowledgePresenter::subject($subject))
                ->values();

            $results['categories'] = KnowledgeCategory::query()
                ->whereHas('subject', $owned)
                ->where('title', 'like', $like)
                ->with('subject')
                ->orderBy('title')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (KnowledgeCategory $category) => [
                    ...KnowledgePresenter::category($category),
                    'subject' => ['id' => $category->subject->id, 'title' => $category->subject->title, 'accent_color' => $category->subject->accent_color->value],
                ])
                ->values();

            $results['pages'] = KnowledgePage::query()
                ->whereHas('subject', $owned)
                ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('content_text', 'like', $like))
                ->with('subject')
                ->orderByDesc('updated_at')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (KnowledgePage $page) => [
                    ...KnowledgePresenter::pageWithSubject($page),
                    'excerpt' => self::excerpt((string) $page->content_text, $query),
                ])
                ->values();
        }

        return Inertia::render('knowledge/search', [
            'query' => $query,
            'subject' => $within === null ? null : ['id' => $within->id, 'title' => $within->title],
            'results' => $results,
        ]);
    }

    /**
     * A short piece of text around the first match, or the start of the text.
     */
    private static function excerpt(string $text, string $query): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        $position = mb_stripos(Str::ascii($text), Str::ascii($query));
        $start = $position === false ? 0 : max(0, $position - 60);
        $excerpt = mb_substr($text, $start, 180);

        return ($start > 0 ? '…' : '').$excerpt.(mb_strlen($text) > $start + 180 ? '…' : '');
    }
}
