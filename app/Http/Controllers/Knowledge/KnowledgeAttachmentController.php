<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\KnowledgePresenter;
use App\Http\Requests\Knowledge\KnowledgeAttachmentReadingRequest;
use App\Http\Requests\Knowledge\KnowledgeAttachmentRequest;
use App\Http\Requests\Knowledge\KnowledgeAttachmentTitleRequest;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use App\Services\Knowledge\KnowledgeAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeAttachmentController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly KnowledgeAttachmentService $attachments)
    {
        //
    }

    /**
     * Upload an image, PDF or Office file into a page (JSON, sent by the editor).
     */
    public function store(KnowledgeAttachmentRequest $request, KnowledgePage $page): JsonResponse
    {
        $attachment = $this->attachments->store($page->subject, $page, $request->upload(), $request->info());

        return response()->json(KnowledgePresenter::attachment($attachment), 201);
    }

    /**
     * Attach a file to the subject itself (JSON, one request per file).
     */
    public function storeForSubject(KnowledgeAttachmentRequest $request, KnowledgeSubject $subject): JsonResponse
    {
        $attachment = $this->attachments->store($subject, null, $request->upload(), $request->info(), subjectFile: true);
        $subject->touch();

        return response()->json(KnowledgePresenter::attachment($attachment), 201);
    }

    public function update(KnowledgeAttachmentTitleRequest $request, KnowledgeAttachment $attachment): RedirectResponse
    {
        $this->attachments->rename($attachment, (string) $request->validated('title'));

        $this->toast('Arquivo renomeado.');

        return back();
    }

    public function destroy(KnowledgeAttachment $attachment): RedirectResponse
    {
        Gate::authorize('delete', $attachment);

        $this->attachments->delete(collect([$attachment]));

        $this->toast('Arquivo excluído.');

        return back();
    }

    /**
     * Where the built-in reader was left (JSON, sent while paging).
     */
    public function reading(KnowledgeAttachmentReadingRequest $request, KnowledgeAttachment $attachment): JsonResponse
    {
        $this->attachments->recordReading($attachment, (int) $request->validated('page'), (int) $request->validated('pages'));

        return response()->json(['ok' => true]);
    }

    /**
     * Serve a file to its owner only. Shown inline (images, PDF viewer) unless
     * ?download=1. The stored type was detected on upload, and nosniff stops
     * the browser from guessing another one.
     */
    public function show(Request $request, KnowledgeAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $headers = [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=604800',
        ];

        return $request->boolean('download')
            ? $disk->download($attachment->path, $attachment->filename, $headers)
            : $disk->response($attachment->path, $attachment->filename, $headers);
    }
}
