<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StudyPresenter;
use App\Models\StudyChapter;
use App\Models\StudyPage;
use App\Models\StudySubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bookshelf of the user's study library and the search across it.
 */
class StudyLibraryController extends Controller
{
    use InteractsWithHousehold;

    private const SEARCH_LIMIT = 50;

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $subjects = $user->studySubjects()
            ->withCount(StudyPresenter::subjectCounts())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $pages = fn () => StudyPage::query()
            ->whereHas('subject', fn (Builder $query) => $query->where('user_id', $user->id)->whereNull('archived_at'))
            ->whereNull('archived_at')
            ->with('subject');

        // The page last read in each subject, to "continue reading".
        $continue = $pages()
            ->whereNotNull('last_read_at')
            ->orderByDesc('last_read_at')
            ->get(['id', 'subject_id', 'title', 'last_read_at'])
            ->unique('subject_id')
            ->mapWithKeys(fn (StudyPage $page) => [$page->subject_id => ['id' => $page->id, 'title' => $page->title]]);

        return Inertia::render('study/index', [
            'subjects' => $subjects
                ->map(fn (StudySubject $subject) => [
                    ...StudyPresenter::subject($subject),
                    'continue_page' => $continue[$subject->id] ?? null,
                ])
                ->values(),
            'recent_pages' => $pages()->whereNotNull('last_read_at')->orderByDesc('last_read_at')->limit(6)->get()
                ->map(fn (StudyPage $page) => StudyPresenter::pageWithSubject($page))->values(),
            'bookmarks' => $pages()->whereNotNull('bookmarked_at')->orderByDesc('bookmarked_at')->limit(30)->get()
                ->map(fn (StudyPage $page) => StudyPresenter::pageWithSubject($page))->values(),
        ]);
    }

    /**
     * Search subject titles and descriptions, chapter titles and page titles
     * and text, optionally within one subject. LIKE keeps it portable between
     * SQLite and MySQL (both match case-insensitively).
     */
    public function search(Request $request): Response
    {
        $user = $this->user($request);
        $query = Str::limit(trim((string) $request->query('q', '')), 100, '');
        $within = $request->integer('subject') > 0
            ? $user->studySubjects()->find($request->integer('subject'))
            : null;
        $results = ['subjects' => [], 'chapters' => [], 'pages' => []];

        if (mb_strlen($query) >= 2) {
            $like = '%'.addcslashes($query, '\\%_').'%';
            $owned = fn (Builder $q) => $q->where('user_id', $user->id)->when($within, fn (Builder $q) => $q->whereKey($within?->id));

            $results['subjects'] = $within !== null ? [] : $user->studySubjects()
                ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like))
                ->withCount(StudyPresenter::subjectCounts())
                ->orderBy('title')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (StudySubject $subject) => StudyPresenter::subject($subject))
                ->values();

            $results['chapters'] = StudyChapter::query()
                ->whereHas('subject', $owned)
                ->where('title', 'like', $like)
                ->with('subject')
                ->orderBy('title')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (StudyChapter $chapter) => [
                    ...StudyPresenter::chapter($chapter),
                    'subject' => ['id' => $chapter->subject->id, 'title' => $chapter->subject->title, 'accent_color' => $chapter->subject->accent_color->value],
                ])
                ->values();

            $results['pages'] = StudyPage::query()
                ->whereHas('subject', $owned)
                ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('content_text', 'like', $like))
                ->with('subject')
                ->orderByDesc('updated_at')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->map(fn (StudyPage $page) => [
                    ...StudyPresenter::pageWithSubject($page),
                    'excerpt' => self::excerpt((string) $page->content_text, $query),
                ])
                ->values();
        }

        return Inertia::render('study/search', [
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
