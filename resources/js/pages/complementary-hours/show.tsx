import { Head, Link } from '@inertiajs/react';
import {
    Calendar,
    Clock,
    Download,
    ExternalLink,
    FileDown,
    FileWarning,
    Pencil,
    Trash2,
} from 'lucide-react';
import { ReportBadge } from '@/components/complementary-hours/report-badge';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { MarkdownView } from '@/components/time/markdown';
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
import type { ComplementaryActivity } from '@/types';

export default function ComplementaryActivityDetails({
    activity,
}: {
    activity: ComplementaryActivity;
}) {
    const certificateUrl = complementaryHours.certificate.url(activity.id);
    const downloadUrl = complementaryHours.certificate.url(activity.id, {
        query: { download: 1 },
    });

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
                            <ReportBadge hasReport={activity.has_report} />
                        </span>
                    }
                >
                    {activity.has_report && (
                        <Button variant="outline" asChild>
                            <a
                                href={complementaryHours.report.url(
                                    activity.id,
                                )}
                                download
                            >
                                <FileDown />
                                Exportar PDF
                            </a>
                        </Button>
                    )}
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
                        description={`“${activity.title}” será removida, com o certificado e o relatório.`}
                        confirmLabel="Excluir"
                        url={complementaryHours.destroy.url(activity.id)}
                    />
                </PageHeader>

                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 space-y-1.5">
                            <CardTitle>Certificado</CardTitle>
                            <CardDescription className="break-all">
                                {activity.certificate.name} ·{' '}
                                {formatFileSize(activity.certificate.size)}
                            </CardDescription>
                        </div>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={certificateUrl}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <ExternalLink />
                                    Abrir
                                </a>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <a href={downloadUrl}>
                                    <Download />
                                    Baixar
                                </a>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {activity.certificate.is_image ? (
                            <img
                                src={certificateUrl}
                                alt={`Certificado de ${activity.title}`}
                                className="mx-auto max-h-[36rem] rounded-md border object-contain"
                            />
                        ) : (
                            <iframe
                                src={certificateUrl}
                                title={`Certificado de ${activity.title}`}
                                className="h-[36rem] w-full rounded-md border"
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Relatório</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activity.report ? (
                            <MarkdownView content={activity.report} />
                        ) : (
                            <EmptyState
                                icon={FileWarning}
                                title="Esta atividade não tem relatório"
                                description="Escreva o relatório em Markdown para poder exportá-lo em PDF."
                            >
                                <Button asChild>
                                    <Link
                                        href={complementaryHours.edit(
                                            activity.id,
                                        )}
                                    >
                                        <Pencil />
                                        Escrever relatório
                                    </Link>
                                </Button>
                            </EmptyState>
                        )}
                    </CardContent>
                </Card>
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
