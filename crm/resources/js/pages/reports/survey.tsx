import Flash from '@/components/campaigns/flash';
import Heading from '@/components/heading';
import Stat from '@/components/stat';
import ConfirmButton from '@/components/surveys/confirm-button';
import { decimal, nps, percent, satisfaction, type SurveyTotals } from '@/components/surveys/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dateTime, day, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Download, RotateCw, Send } from 'lucide-react';

interface Count {
    label: string;
    count: number;
}

type Question = { key: string; label: string; answers: number } & (
    | { type: 'rating'; average: number | null; counts: Count[] }
    | { type: 'nps'; nps: number | null; counts: Count[] }
    | { type: 'choice'; counts: Count[] }
    | { type: 'text'; comments: { text: string; at: string }[] }
);

interface Person {
    id: number;
    name: string;
    email: string | null;
    queued: boolean;
    sent_at: string | null;
    failed_at: string | null;
    error: string | null;
    opened: boolean;
    responded: boolean;
}

interface PageProps {
    survey: {
        id: number;
        title: string;
        activity_name: string;
        activity_type: string;
        activity_date: string | null;
        source: string;
        require_check_in: boolean;
        created_at: string;
    };
    totals: SurveyTotals & { invited: number };
    questions: Question[];
    people: Person[];
    types: Record<string, string>;
    canManage: boolean;
    mailReady: boolean;
    [key: string]: unknown;
}

export default function SurveyResults() {
    const { survey, totals, questions, people, types, canManage, mailReady } = usePage<PageProps>().props;
    const year = survey.activity_date?.slice(0, 4) ?? '';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Reportes', href: `/reports?anio=${year}` },
        { title: 'Encuestas', href: `/reports/encuestas?anio=${year}` },
        { title: survey.activity_name, href: `/reports/encuestas/${survey.id}` },
    ];
    const pending = people.filter((p) => p.email && !p.sent_at && !p.queued && !p.responded).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Encuesta · ${survey.activity_name}`} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={survey.activity_name}
                        description={`${types[survey.activity_type] ?? survey.activity_type} · ${day(survey.activity_date)} · ${
                            survey.source === 'auto' ? 'Salió sola' : 'Enviada a mano'
                        } ${dateTime.format(new Date(survey.created_at))} · ${survey.require_check_in ? 'solo con check-in' : 'todos los que pagaron'}`}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={`/reports/encuestas/${survey.id}/csv`}>
                                <Download aria-hidden />
                                Respuestas (CSV)
                            </a>
                        </Button>
                        {canManage && (
                            <ConfirmButton
                                disabled={!mailReady}
                                title="Enviar a quienes faltan"
                                description="Le llega a quienes cumplen y aún no la recibieron: check-in tardío o un envío que falló. Nadie la recibe dos veces."
                                confirm="Enviar"
                                onConfirm={() => router.post(`/encuestas/${survey.id}/enviar`, {}, { preserveScroll: true })}
                            >
                                <Send aria-hidden />
                                Enviar a quienes faltan
                            </ConfirmButton>
                        )}
                    </div>
                </div>

                <Flash />

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Stat label="Enviadas" value={totals.sent} display={`${number.format(totals.sent)} de ${number.format(totals.invited)}`} />
                    <Stat label="Respuestas" value={totals.responded} display={`${number.format(totals.responded)} · ${percent(totals.rate)}`} />
                    <Stat label="Satisfacción promedio" value={totals.satisfaction ?? 0} display={satisfaction(totals.satisfaction)} />
                    <Stat label="NPS" value={totals.nps ?? 0} display={nps(totals.nps)} />
                </dl>

                <div className="grid gap-4 lg:grid-cols-2">
                    {questions.map((q) => (
                        <section key={q.key} className="space-y-3 rounded-xl border p-4" aria-labelledby={`q-${q.key}`}>
                            <div>
                                <h2 id={`q-${q.key}`} className="font-medium">
                                    {q.label}
                                </h2>
                                <p className="text-muted-foreground text-xs">
                                    {number.format(q.answers)} {q.answers === 1 ? 'respuesta' : 'respuestas'}
                                </p>
                            </div>
                            <QuestionBody question={q} />
                        </section>
                    ))}
                </div>

                <section className="space-y-3" aria-labelledby="people-heading">
                    <div>
                        <h2 id="people-heading" className="text-base font-semibold">
                            A quién le llegó
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Solo se ve si respondió; lo que cada persona contestó es confidencial.
                            {pending > 0 && ` ${number.format(pending)} sin enviar todavía.`}
                        </p>
                    </div>
                    {people.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-center text-sm">
                            Nadie cumple todavía: la encuesta le llega a quien hizo check-in en esta actividad.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {people.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 text-sm">
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium">{p.name}</span>
                                        <span className="text-muted-foreground block truncate text-xs">{p.email ?? 'Sin correo'}</span>
                                    </span>
                                    <PersonStatus person={p} />
                                    {canManage && p.email && !p.responded && (p.sent_at || p.failed_at) && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={!mailReady}
                                            onClick={() => router.post(`/encuestas/persona/${p.id}/reenviar`, {}, { preserveScroll: true })}
                                        >
                                            <RotateCw aria-hidden />
                                            Reenviar
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function PersonStatus({ person: p }: { person: Person }) {
    if (p.responded) return <Badge>Respondió</Badge>;
    if (p.failed_at && !p.sent_at)
        return (
            <Badge variant="destructive" title={p.error ?? undefined}>
                No se pudo enviar
            </Badge>
        );
    if (p.queued) return <Badge variant="secondary">En cola</Badge>;
    if (p.sent_at) return <Badge variant="outline">{p.opened ? 'La abrió' : 'Enviada'}</Badge>;
    return <span className="text-muted-foreground text-xs">{p.email ? 'Sin enviar' : 'Sin correo'}</span>;
}

function QuestionBody({ question: q }: { question: Question }) {
    switch (q.type) {
        case 'rating':
            return (
                <div className="space-y-3">
                    <p className="text-2xl font-semibold tabular-nums">
                        {q.average === null ? '—' : decimal.format(q.average)}{' '}
                        <span className="text-muted-foreground text-base font-normal">/ 5</span>
                    </p>
                    <Bars items={[...q.counts].reverse().map((c) => ({ ...c, label: `${c.label} ★` }))} />
                </div>
            );
        case 'nps':
            return (
                <div className="space-y-3">
                    <p className="text-2xl font-semibold tabular-nums">
                        {nps(q.nps)} <span className="text-muted-foreground text-base font-normal">NPS</span>
                    </p>
                    <Bars items={q.counts} />
                </div>
            );
        case 'choice':
            return <Bars items={q.counts} />;
        case 'text':
            return q.comments.length === 0 ? (
                <p className="text-muted-foreground text-sm">Sin comentarios todavía.</p>
            ) : (
                <ul className="max-h-80 space-y-2 overflow-y-auto pr-1 text-sm">
                    {q.comments.map((c, i) => (
                        <li key={i} className="bg-muted/40 rounded-lg p-3">
                            <p className="whitespace-pre-line">{c.text}</p>
                            <p className="text-muted-foreground mt-1 text-xs">{dateTime.format(new Date(c.at))}</p>
                        </li>
                    ))}
                </ul>
            );
    }
}

/** Horizontal bars with the count and share beside each one; one hue. */
function Bars({ items }: { items: Count[] }) {
    const total = items.reduce((s, i) => s + i.count, 0);
    const max = Math.max(1, ...items.map((i) => i.count));
    return (
        <ul className="space-y-2 text-sm">
            {items.map((i) => (
                <li key={i.label} className="grid grid-cols-[minmax(0,8rem)_1fr_4.5rem] items-center gap-3">
                    <span className="truncate" title={i.label}>
                        {i.label}
                    </span>
                    <span className="bg-muted h-2 overflow-hidden rounded-full" aria-hidden>
                        <span className="bg-primary block h-full rounded-full" style={{ width: `${(i.count / max) * 100}%` }} />
                    </span>
                    <span className="text-right tabular-nums">
                        {number.format(i.count)}
                        <span className="text-muted-foreground"> · {total ? Math.round((i.count / total) * 100) : 0}%</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}
