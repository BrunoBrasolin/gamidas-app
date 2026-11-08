import type { ComplementaryAttachmentKind } from '@/types';

/** In the order attachments are listed. */
export const attachmentKinds: ComplementaryAttachmentKind[] = [
    'certificate',
    'report',
    'other',
];

export const attachmentKindLabels: Record<ComplementaryAttachmentKind, string> =
    {
        certificate: 'Certificado',
        report: 'Relatório',
        other: 'Outro',
    };

/** Section titles on the details page. */
export const attachmentKindGroups: Record<ComplementaryAttachmentKind, string> =
    {
        certificate: 'Certificados',
        report: 'Relatório',
        other: 'Outros arquivos',
    };

const pdfAndImages =
    'application/pdf,image/jpeg,image/png,image/webp,image/gif,.pdf,.jpg,.jpeg,.png,.webp,.gif';
const office =
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation,.docx,.xlsx,.pptx';

/** File types the server accepts for each kind (checked again there). */
export const attachmentKindAccept: Record<ComplementaryAttachmentKind, string> =
    {
        certificate: pdfAndImages,
        report: `${pdfAndImages},${office}`,
        other: `${pdfAndImages},${office}`,
    };
