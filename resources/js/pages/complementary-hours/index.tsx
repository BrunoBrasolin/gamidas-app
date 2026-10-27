import { Head, Link } from '@inertiajs/react';
import {
    Calendar,
    CheckCircle2,
    Clock,
    FileWarning,
    GraduationCap,
    Hourglass,
    Paperclip,
    Plus,
    Target,
} from 'lucide-react';
import { GoalDialog } from '@/components/complementary-hours/goal-dialog';
import { ReportBadge } from '@/components/complementary-hours/report-badge';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { formatHours } from '@/lib/hours';
import complementaryHours from '@/routes/complementary-hours';
import type { ComplementaryActivity, ComplementaryHoursSummary } from '@/types';

function Progress({ summary }: { summary: ComplementaryHoursSummary }) {
    const required = summary.required_minutes;

    if (required === null) {
        return (
            <Card>
                <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <p className="font-medium">
                            Quantas horas o seu curso exige?
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Defina a meta para acompanhar quanto já foi feito e
                            quanto falta.
                        </p>
                    </div>
                    <GoalDialog
                        requiredMinutes={null}
                        trigger={
                            <Button>
                                <Target />
                                Definir meta
                            </Button>
                        }
                    />
                </CardContent>
            </Card>
        );
    }

    const ratio = Math.min(
        100,
        Math.round((summary.done_minutes / required) * 100),
    );
    const complete = summary.remaining_minutes === 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {formatHours(summary.done_minutes)} de{' '}
                    {formatHours(required)}
                </CardTitle>
                <CardDescription>
                    {complete
                        ? 'Meta concluída! 🎓'
                        : `Faltam ${formatHours(summary.remaining_minutes ?? 0)} · ${ratio}% concluído`}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    className="h-2.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-valuenow={ratio}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label="Horas concluídas"
                >
                    <div
                        className={
                            complete
                                ? 'h-full bg-emerald-500'
                                : 'h-full bg-primary'
                        }
                        style={{ width: `${ratio}%` }}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

function ActivityCard({ activity }: { activity: ComplementaryActivity }) {
    return (
        <Card className="py-4 transition-colors hover:bg-accent/40">
            <CardContent className="px-4">
                <Link
                    href={complementaryHours.show(activity.id)}
                    className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                    prefetch
                >
                    <div className="min-w-0 space-y-1.5">
                        <p className="font-medium break-words">
                            {activity.title}
                        </p>
                        <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            {activity.completed_on && (
                                <span className="flex items-center gap-1.5">
                                    <Calendar
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                    {formatDate(activity.completed_on)}
                                </span>
                            )}
                            <span className="flex min-w-0 items-center gap-1.5">
                                <Paperclip
                                    className="size-3.5 shrink-0"
                                    aria-hidden
                                />
                                <span className="truncate">
                                    {activity.certificate.name}
                                </span>
                            </span>
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <ReportBadge hasReport={activity.has_report} />
                        <Badge className="tabular-nums">
                            <Clock aria-hidden />
                            {formatHours(activity.minutes)}
                        </Badge>
                    </div>
                </Link>
            </CardContent>
        </Card>
    );
}

export default function ComplementaryHoursIndex({
    summary,
    activities,
}: {
    summary: ComplementaryHoursSummary;
    activities: ComplementaryActivity[];
}) {
    return (
        <>
            <Head title="Horas complementares" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Horas complementares"
                    description="As atividades da faculdade com certificado, carga horária e relatório."
                >
                    {summary.required_minutes !== null && (
                        <GoalDialog
                            requiredMinutes={summary.required_minutes}
                            trigger={
                                <Button variant="outline">
                                    <Target />
                                    Meta
                                </Button>
                            }
                        />
                    )}
                    <Button asChild>
                        <Link href={complementaryHours.create()}>
                            <Plus />
                            Nova atividade
                        </Link>
                    </Button>
                </PageHeader>

                <Progress summary={summary} />

                {activities.length === 0 ? (
                    <EmptyState
                        icon={GraduationCap}
                        title="Nenhuma atividade ainda"
                        description="Cadastre a primeira atividade com o título, as horas e o certificado. O relatório pode ser escrito depois."
                    />
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatCard
                                title="Concluídas"
                                value={formatHours(summary.done_minutes)}
                                icon={CheckCircle2}
                            />
                            <StatCard
                                title="Faltam"
                                value={
                                    summary.remaining_minutes === null
                                        ? '—'
                                        : formatHours(summary.remaining_minutes)
                                }
                                hint={
                                    summary.required_minutes === null
                                        ? 'Defina a meta'
                                        : undefined
                                }
                                icon={Hourglass}
                            />
                            <StatCard
                                title="Atividades"
                                value={summary.activities}
                                icon={GraduationCap}
                            />
                            <StatCard
                                title="Sem relatório"
                                value={summary.without_report}
                                icon={FileWarning}
                            />
                        </div>

                        <ul className="grid gap-3">
                            {activities.map((activity) => (
                                <li key={activity.id}>
                                    <ActivityCard activity={activity} />
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </>
    );
}

ComplementaryHoursIndex.layout = {
    breadcrumbs: [
        { title: 'Horas complementares', href: complementaryHours.index() },
    ],
};
