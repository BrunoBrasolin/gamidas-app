<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Presenters\KnowledgePresenter;
use App\Http\Requests\Knowledge\KnowledgeAttachmentRequest;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgePage;
use App\Services\Knowledge\KnowledgeAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeAttachmentController extends Controller
{
    public function __construct(private readonly KnowledgeAttachmentService $attachments)
    {
        //
    }

    /**
     * Upload an image or PDF into a page (JSON, sent by the editor).
     */
    public function store(KnowledgeAttachmentRequest $request, KnowledgePage $page): JsonResponse
    {
        $attachment = $this->attachments->store($page->subject, $page, $request->upload(), $request->info());

        return response()->json(KnowledgePresenter::attachment($attachment), 201);
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
