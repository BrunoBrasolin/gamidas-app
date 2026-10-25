<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Real image and PDF bytes for upload tests, built without GD so the tests
 * run on any PHP build.
 */
trait CreatesKnowledgeFiles
{
    /**
     * A PNG of the given size filled with noise, so it does not compress and
     * its size grows with its dimensions.
     */
    protected function png(string $name = 'imagem.png', int $width = 4, int $height = 3): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = '';

        for ($row = 0; $row < $height; $row++) {
            $rows .= "\0".random_bytes($width * 3);
        }

        $bytes = "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($rows))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function pdf(string $name = 'apostila.pdf'): UploadedFile
    {
        $bytes = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
