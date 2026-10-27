<?php

namespace App\Support;

use App\Enums\KnowledgeAttachmentKind;
use Illuminate\Http\UploadedFile;

/**
 * Inspects an uploaded knowledge file by its contents, never by the name or the
 * MIME type sent by the browser. Only raster images (no SVG), real PDFs and
 * Office Open XML files (PowerPoint, Word, Excel) are accepted.
 */
final class KnowledgeUpload
{
    /** Detected MIME type => stored extension. */
    public const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Office Open XML files: the part that identifies each one inside the ZIP,
     * the MIME type libmagic reports for it, and what is stored.
     */
    private const OFFICE_TYPES = [
        'ppt/presentation.xml' => [
            'detected' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'kind' => KnowledgeAttachmentKind::Slides,
            'extension' => 'pptx',
        ],
        'word/document.xml' => [
            'detected' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'kind' => KnowledgeAttachmentKind::Document,
            'extension' => 'docx',
        ],
        'xl/workbook.xml' => [
            'detected' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'kind' => KnowledgeAttachmentKind::Sheet,
            'extension' => 'xlsx',
        ],
    ];

    /**
     * @return array{kind: KnowledgeAttachmentKind, mime: string, extension: string, width: int|null, height: int|null}|null
     */
    public static function inspect(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();

        if ($path === false || ! $file->isValid()) {
            return null;
        }

        // Read the type from the bytes on disk (finfo), never from the name.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (isset(self::IMAGE_TYPES[$mime])) {
            $size = @getimagesize($path);

            if ($size === false || $size[0] < 1 || $size[1] < 1 || ! isset(self::IMAGE_TYPES[$size['mime']])) {
                return null;
            }

            return [
                'kind' => KnowledgeAttachmentKind::Image,
                'mime' => $size['mime'],
                'extension' => self::IMAGE_TYPES[$size['mime']],
                'width' => $size[0],
                'height' => $size[1],
            ];
        }

        if ($mime === 'application/pdf') {
            $handle = fopen($path, 'rb');
            $header = $handle === false ? '' : (string) fread($handle, 1024);

            if ($handle !== false) {
                fclose($handle);
            }

            if (! str_contains($header, '%PDF-')) {
                return null;
            }

            return ['kind' => KnowledgeAttachmentKind::Pdf, 'mime' => 'application/pdf', 'extension' => 'pdf', 'width' => null, 'height' => null];
        }

        return self::office($path, $mime);
    }

    /**
     * An Office Open XML file is a ZIP whose parts say what it is. With the zip
     * extension the parts are looked up (nothing is decompressed); without it
     * libmagic's answer, which also reads the parts, is used.
     *
     * @return array{kind: KnowledgeAttachmentKind, mime: string, extension: string, width: null, height: null}|null
     */
    private static function office(string $path, string $mime): ?array
    {
        if (! class_exists(\ZipArchive::class)) {
            foreach (self::OFFICE_TYPES as $type) {
                if ($type['detected'] === $mime) {
                    return ['kind' => $type['kind'], 'mime' => $type['detected'], 'extension' => $type['extension'], 'width' => null, 'height' => null];
                }
            }

            return null;
        }

        if (! in_array($mime, ['application/zip', 'application/octet-stream', ...array_column(self::OFFICE_TYPES, 'detected')], true)) {
            return null;
        }

        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return null;
            }

            foreach (self::OFFICE_TYPES as $part => $type) {
                if ($zip->locateName($part) !== false) {
                    return ['kind' => $type['kind'], 'mime' => $type['detected'], 'extension' => $type['extension'], 'width' => null, 'height' => null];
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Size limit in KB for a kind of attachment.
     */
    public static function maxKilobytes(KnowledgeAttachmentKind $kind): int
    {
        return $kind === KnowledgeAttachmentKind::Image
            ? (int) config('knowledge.max_image_kb')
            : (int) config('knowledge.max_file_kb');
    }

    /**
     * A display name safe to keep: no path, no control characters, at most
     * 200 characters, and always ending with the detected extension.
     */
    public static function displayName(string $original, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|:*?\/\\\\]+/u', '', $name));
        $base = trim((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name));

        if ($base === '') {
            $base = in_array($extension, self::IMAGE_TYPES, true) ? 'imagem' : 'documento';
        }

        return mb_substr($base, 0, 190).'.'.$extension;
    }
}
