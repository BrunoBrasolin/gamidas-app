<?php

namespace App\Enums;

/**
 * What a knowledge attachment is. Values fit the 10-character column.
 */
enum KnowledgeAttachmentKind: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    /** PowerPoint (.pptx) */
    case Slides = 'slides';
    /** Word (.docx) */
    case Document = 'document';
    /** Excel (.xlsx) */
    case Sheet = 'sheet';

    /**
     * Subject of the error messages ("O PDF pode ter no máximo…").
     */
    public function label(): string
    {
        return match ($this) {
            self::Image => 'A imagem',
            self::Pdf => 'O PDF',
            self::Slides => 'A apresentação',
            self::Document => 'O documento',
            self::Sheet => 'A planilha',
        };
    }

    /**
     * Whether the reader shows it page by page (and remembers the page).
     */
    public function isPaged(): bool
    {
        return in_array($this, [self::Pdf, self::Slides, self::Document], true);
    }
}
