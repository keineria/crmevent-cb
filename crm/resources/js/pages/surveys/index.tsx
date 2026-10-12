import Flash from '@/components/campaigns/flash';
import { selectClass } from '@/components/finance/period-filter';
import Heading from '@/components/heading';
import ConfirmButton, { delayLabel } from '@/components/surveys/confirm-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dateTime, day, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChartColumn, ChevronRight, Clock, ListChecks, Mail, Send, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

interface Form {
    type: string;
    label: string;
    enabled: boolean;
    title: string | null;
    questions: number;
    delay_minutes: number | null;
    require_check_in: boolean;
}

interface Activity {
    id: number;
    type: string;
    name: string;
    date: string | null;
    start_time: string | null;
    end_time: string | null;
    ended: boolean;
    sends_at: string | null;
    survey: { id: number; source: string; created_at: string; invited: number; sent: number; responded: number } | null;
}

interface PageProps {
    forms: Form[];
    activities: Activity[];
    types: Record<string, string>;
    mailReady: boolean;
    perHour: number;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Encuestas', href: '/encuestas' }];

export default function SurveysIndex() {
    const { forms, activities, types, mailReady, perHour } = usePage<PageProps>().props;
    const [type, setType] = useState('');
    const shown = activities.filter((a) => !type || a.type === type);

    const toggle = (form: Form) => router.put(`/encuestas/preguntas/${form.type}/activa`, { enabled: !form.enabled }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Encuestas" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Encuestas de satisfacción"
                        description="Cuando termina cada actividad, quienes hicieron check-in reciben una encuesta corta por correo. Los resultados quedan en Reportes."
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/encuestas/correo">
                                <Mail aria-hidden />
                                Correo de la encuesta
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/reports/encuestas">
                                <ChartColumn aria-hidden />
                                Resultados
                            </Link>
                        </Button>
                    </div>
                </div>

                <Flash />

                {!mailReady && (
                    <Alert variant="destructive">
                        <TriangleAlert className="h-4 w-4" aria-hidden />
                        <AlertDescription>
                            No sale ninguna encuesta hasta que el correo transaccional esté verificado en Configuración › Correo de envíos.
                        </AlertDescription>
                    </Alert>
                )}

                <section aria-labelledby="forms-heading" className="space-y-3">
                    <h2 id="forms-heading" className="text-base font-semibold">
                        Preguntas por tipo de actividad
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {forms.map((f) => (
                            <div key={f.type} className="flex flex-col gap-3 rounded-xl border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="font-medium">{f.label}</h3>
                                    <Badge variant={f.enabled ? 'default' : 'secondary'}>{f.enabled ? 'Automática' : 'Apagada'}</Badge>
                                </div>
                                <p className="text-muted-foreground flex-1 text-sm">
                                    {number.format(f.questions)} {f.questions === 1 ? 'pregunta' : 'preguntas'}
                                    {f.delay_minutes !== null && ` · sale ${delayLabel(f.delay_minutes)}`}
                                    {f.require_check_in ? ' · solo con check-in' : ' · todos los que pagaron'}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={`/encuestas/preguntas/${f.type}`}>
                                            <ListChecks aria-hidden />
                                            Editar preguntas
                                        </Link>
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => toggle(f)}>
                                        {f.enabled ? 'Apagar' : 'Encender'}
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                <section aria-labelledby="activities-heading" className="space-y-3">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="activities-heading" className="text-base font-semibold">
                                Actividades
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                Salen {number.format(perHour)} correos por hora. Una actividad que ya terminó sin encuesta se puede enviar a mano.
                            </p>
                        </div>
                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground text-xs">Tipo</span>
                            <select className={selectClass} value={type} onChange={(e) => setType(e.target.value)}>
                                <option value="">Todas</option>
                                {Object.entries(types).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>

                    {shown.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                            No hay actividades sincronizadas del plugin.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {shown.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-sm">
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">{a.name}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {types[a.type] ?? a.type} · {day(a.date)}
                                            {a.start_time ? ` · ${a.start_time}` : ''}
                                            {a.end_time ? `–${a.end_time}` : ''}
                                        </p>
                                    </div>
                                    <ActivityStatus activity={a} mailReady={mailReady} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function ActivityStatus({ activity: a, mailReady }: { activity: Activity; mailReady: boolean }) {
    if (a.survey) {
        return (
            <Link
                href={`/reports/encuestas/${a.survey.id}`}
                className="hover:bg-muted/40 focus-visible:ring-ring -my-1 flex items-center gap-2 rounded-md px-2 py-1 focus-visible:ring-2 focus-visible:outline-hidden"
            >
                <span className="tabular-nums">
                    {number.format(a.survey.sent)} de {number.format(a.survey.invited)} enviadas · {number.format(a.survey.responded)} respuestas
                </span>
                <ChevronRight className="text-muted-foreground h-4 w-4" aria-hidden />
            </Link>
        );
    }

    if (a.sends_at) {
        return (
            <span className="text-muted-foreground flex items-center gap-1">
                <Clock className="h-4 w-4" aria-hidden />
                Sale sola {dateTime.format(new Date(a.sends_at))}
            </span>
        );
    }

    if (a.ended) {
        return (
            <ConfirmButton
                variant="outline"
                size="sm"
                disabled={!mailReady}
                title={`Enviar la encuesta de ${a.name}`}
                description="Le llega a quienes hicieron check-in en esta actividad. Cada persona la recibe una sola vez."
                confirm="Enviar"
                onConfirm={() => router.post(`/encuestas/actividad/${a.id}/enviar`, {}, { preserveScroll: true })}
            >
                <Send aria-hidden />
                Enviar ahora
            </ConfirmButton>
        );
    }

    return <span className="text-muted-foreground">Automática apagada</span>;
}
