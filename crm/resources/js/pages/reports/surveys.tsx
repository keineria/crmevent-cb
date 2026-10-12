import { selectClass } from '@/components/finance/period-filter';
import Heading from '@/components/heading';
import Stat from '@/components/stat';
import { nps, percent, satisfaction, type SurveyTotals } from '@/components/surveys/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { day, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, MessageSquareHeart, Settings2 } from 'lucide-react';

interface Row extends SurveyTotals {
    id: number;
    activity_name: string;
    activity_type: string;
    activity_date: string | null;
    invited: number;
}

interface PageProps {
    year: number;
    years: number[];
    surveys: Row[];
    totals: SurveyTotals & { surveys: number };
    types: Record<string, string>;
    canManage: boolean;
    [key: string]: unknown;
}

export default function SurveyReport() {
    const { year, years, surveys, totals, types, canManage } = usePage<PageProps>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Reportes', href: `/reports?anio=${year}` },
        { title: 'Encuestas', href: `/reports/encuestas?anio=${year}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Encuestas · Reportes" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Encuestas de satisfacción · feria ${year}`}
                        description="Satisfacción promedio de 1 a 5 y NPS (de −100 a +100: % que nos recomendaría menos % que no) de cada actividad."
                    />
                    <div className="flex flex-wrap items-end gap-2">
                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground text-xs">Feria</span>
                            <select
                                className={selectClass}
                                value={year}
                                onChange={(e) => router.get('/reports/encuestas', { anio: e.target.value }, { preserveState: false })}
                            >
                                {years.map((y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {canManage && (
                            <Button variant="outline" asChild>
                                <Link href="/encuestas">
                                    <Settings2 aria-hidden />
                                    Preguntas y envío
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    <Stat label="Actividades encuestadas" value={totals.surveys} />
                    <Stat label="Encuestas enviadas" value={totals.sent} />
                    <Stat label="Respuestas" value={totals.responded} display={`${number.format(totals.responded)} · ${percent(totals.rate)}`} />
                    <Stat label="Satisfacción promedio" value={totals.satisfaction ?? 0} display={satisfaction(totals.satisfaction)} />
                    <Stat label="NPS" value={totals.nps ?? 0} display={nps(totals.nps)} />
                </dl>

                {surveys.length === 0 ? (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center text-sm">
                        <MessageSquareHeart className="text-primary h-8 w-8" aria-hidden />
                        Aún no hay encuestas de esta feria. Salen solas cuando termina cada actividad.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Actividad
                                    </th>
                                    <th scope="col" className="px-4 py-2 text-right font-medium">
                                        Enviadas
                                    </th>
                                    <th scope="col" className="px-4 py-2 text-right font-medium">
                                        Respuestas
                                    </th>
                                    <th scope="col" className="px-4 py-2 text-right font-medium">
                                        Satisfacción
                                    </th>
                                    <th scope="col" className="px-4 py-2 text-right font-medium">
                                        NPS
                                    </th>
                                    <th scope="col" className="w-8">
                                        <span className="sr-only">Abrir</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {surveys.map((s) => (
                                    <tr
                                        key={s.id}
                                        className="hover:bg-muted/40 cursor-pointer"
                                        onClick={() => router.visit(`/reports/encuestas/${s.id}`)}
                                    >
                                        <td className="px-4 py-3">
                                            <Link href={`/reports/encuestas/${s.id}`} className="font-medium hover:underline">
                                                {s.activity_name}
                                            </Link>
                                            <span className="text-muted-foreground block text-xs">
                                                {types[s.activity_type] ?? s.activity_type} · {day(s.activity_date)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{number.format(s.sent)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {number.format(s.responded)} <span className="text-muted-foreground">· {percent(s.rate)}</span>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{satisfaction(s.satisfaction)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{nps(s.nps)}</td>
                                        <td className="pr-3">
                                            <ChevronRight className="text-muted-foreground h-4 w-4" aria-hidden />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
