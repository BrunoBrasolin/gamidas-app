import { Head, Link } from '@inertiajs/react';
import {
    Calendar,
    Clock,
    Download,
    ExternalLink,
    Paperclip,
    Pencil,
    Trash2,
} from 'lucide-react';
import {
    attachmentKindGroups,
    attachmentKinds,
} from '@/components/complementary-hours/attachment-kinds';
import { ReportBadge } from '@/components/complementary-hours/report-badge';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { formatFileSize, formatHours } from '@/lib/hours';
import complementaryHours from '@/routes/complementary-hours';
import type { ComplementaryActivity, ComplementaryAttachment } from '@/types';

function AttachmentCard({
    activity,
    attachment,
}: {
    activity: ComplementaryActivity;
    attachment: ComplementaryAttachment;
}) {
    const params = { activity: activity.id, attachment: attachment.id };
    const url = complementaryHours.attachments.show.url(params);
    const downloadUrl = complementaryHours.attachments.show.url(params, {
        query: { download: 1 },
    });
    const previewable = attachment.is_image || attachment.is_pdf;

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1.5">
                    <CardTitle className="text-base break-all">
                        {attachment.name}
                    </CardTitle>
                    <CardDescription>
                        {formatFileSize(attachment.size)}
                    </CardDescription>
                </div>
                <div className="flex gap-2">
                    {previewable && (
                        <Button variant="outline" size="sm" asChild>
                            <a href={url} target="_blank" rel="noopener">
                                <ExternalLink />
                                Abrir
                            </a>
                        </Button>
                    )}
                    <Button variant="outline" size="sm" asChild>
                        <a href={downloadUrl}>
                            <Download />
                            Baixar
                        </a>
                    </Button>
                </div>
            </CardHeader>
            {previewable && (
                <CardContent>
                    {attachment.is_image ? (
                        <img
                            src={url}
                            alt={attachment.name}
                            className="mx-auto max-h-[36rem] rounded-md border object-contain"
                        />
                    ) : (
                        <iframe
                            src={url}
                            title={attachment.name}
                            className="h-[36rem] w-full rounded-md border"
                        />
                    )}
                </CardContent>
            )}
        </Card>
    );
}

export default function ComplementaryActivityDetails({
    activity,
}: {
    activity: ComplementaryActivity;
}) {
    const groups = attachmentKinds
        .map((kind) => ({
            kind,
            attachments: activity.attachments.filter(
                (attachment) => attachment.kind === kind,
            ),
        }))
        .filter((group) => group.attachments.length > 0);

    return (
        <>
            <Head title={activity.title} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={activity.title}
                    description={
                        <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span className="flex items-center gap-1.5">
                                <Clock className="size-3.5" aria-hidden />
                                {formatHours(activity.minutes)}
                            </span>
                            {activity.completed_on && (
                                <span className="flex items-center gap-1.5">
                                    <Calendar
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                    {formatDate(activity.completed_on)}
                                </span>
                            )}
                            <ReportBadge reportDone={activity.report_done} />
                        </span>
                    }
                >
                    <Button variant="outline" asChild>
                        <Link href={complementaryHours.edit(activity.id)}>
                            <Pencil />
                            Editar
                        </Link>
                    </Button>
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Excluir atividade"
                            >
                                <Trash2 />
                            </Button>
                        }
                        title="Excluir atividade?"
                        description={`“${activity.title}” será removida, com todos os anexos.`}
                        confirmLabel="Excluir"
                        url={complementaryHours.destroy.url(activity.id)}
                    />
                </PageHeader>

                {groups.length === 0 ? (
                    <EmptyState
                        icon={Paperclip}
                        title="Nenhum anexo"
                        description="Anexe o certificado, o relatório e outros arquivos da atividade."
                    >
                        <Button asChild>
                            <Link href={complementaryHours.edit(activity.id)}>
                                <Pencil />
                                Anexar arquivos
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    groups.map((group) => (
                        <section key={group.kind} className="grid gap-3">
                            <h2 className="text-lg font-semibold">
                                {attachmentKindGroups[group.kind]}
                            </h2>
                            {group.attachments.map((attachment) => (
                                <AttachmentCard
                                    key={attachment.id}
                                    activity={activity}
                                    attachment={attachment}
                                />
                            ))}
                        </section>
                    ))
                )}
            </div>
        </>
    );
}

ComplementaryActivityDetails.layout = {
    breadcrumbs: [
        { title: 'Horas complementares', href: complementaryHours.index() },
        { title: 'Atividade', href: complementaryHours.index() },
    ],
};
