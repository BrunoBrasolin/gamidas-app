<?php

namespace App\Enums;

/**
 * What a file attached to a complementary activity is. Values fit the
 * 16-character column.
 */
enum ComplementaryAttachmentKind: string
{
    case Certificate = 'certificate';
    /** The college's standard report form, filled in. */
    case Report = 'report';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Certificate => 'Certificado',
            self::Report => 'Relatório',
            self::Other => 'Outro',
        };
    }

    /**
     * Whether a file detected as this knowledge kind can be attached as this
     * kind: certificates are PDFs or images; reports and other files may also
     * be Word, Excel or PowerPoint documents.
     */
    public function accepts(KnowledgeAttachmentKind $kind): bool
    {
        return $this !== self::Certificate
            || in_array($kind, [KnowledgeAttachmentKind::Pdf, KnowledgeAttachmentKind::Image], true);
    }

    /**
     * The message shown when a file of another type is sent as this kind.
     */
    public function invalidTypeMessage(): string
    {
        return $this === self::Certificate
            ? 'Envie o certificado em PDF ou como imagem (JPEG, PNG, WebP ou GIF).'
            : 'Envie um PDF, uma imagem (JPEG, PNG, WebP ou GIF) ou um documento do Word, Excel ou PowerPoint.';
    }

    /**
     * Order in which attachments are listed.
     */
    public function position(): int
    {
        return match ($this) {
            self::Certificate => 0,
            self::Report => 1,
            self::Other => 2,
        };
    }
}
