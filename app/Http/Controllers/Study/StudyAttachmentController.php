<?php

namespace App\Http\Controllers\Study;

use App\Http\Controllers\Controller;
use App\Http\Presenters\StudyPresenter;
use App\Http\Requests\Study\StudyAttachmentRequest;
use App\Models\StudyAttachment;
use App\Models\StudyPage;
use App\Services\Study\StudyAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudyAttachmentController extends Controller
{
    public function __construct(private readonly StudyAttachmentService $attachments)
    {
        //
    }

    /**
     * Upload an image or PDF into a page (JSON, sent by the editor).
     */
    public function store(StudyAttachmentRequest $request, StudyPage $page): JsonResponse
    {
        $attachment = $this->attachments->store($page->subject, $page, $request->upload(), $request->info());

        return response()->json(StudyPresenter::attachment($attachment), 201);
    }

    /**
     * Serve a file to its owner only. Shown inline (images, PDF viewer) unless
     * ?download=1. The stored type was detected on upload, and nosniff stops
     * the browser from guessing another one.
     */
    public function show(Request $request, StudyAttachment $attachment): StreamedResponse
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
