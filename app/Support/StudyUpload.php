<?php

namespace App\Support;

use App\Enums\StudyAttachmentKind;
use Illuminate\Http\UploadedFile;

/**
 * Inspects an uploaded study file by its contents, never by the name or the
 * MIME type sent by the browser. Only raster images (no SVG) and real PDFs
 * are accepted.
 */
final class StudyUpload
{
    /** Detected MIME type => stored extension. */
    public const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * @return array{kind: StudyAttachmentKind, mime: string, extension: string, width: int|null, height: int|null}|null
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
                'kind' => StudyAttachmentKind::Image,
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

            return ['kind' => StudyAttachmentKind::Pdf, 'mime' => 'application/pdf', 'extension' => 'pdf', 'width' => null, 'height' => null];
        }

        return null;
    }

    /**
     * Size limit in KB for a kind of attachment.
     */
    public static function maxKilobytes(StudyAttachmentKind $kind): int
    {
        return $kind === StudyAttachmentKind::Image
            ? (int) config('study.max_image_kb')
            : (int) config('study.max_pdf_kb');
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
            $base = $extension === 'pdf' ? 'documento' : 'imagem';
        }

        return mb_substr($base, 0, 190).'.'.$extension;
    }
}
