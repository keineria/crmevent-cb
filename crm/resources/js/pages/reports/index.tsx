import Heading from '@/components/heading';
import PeopleDialog from '@/components/reports/people-dialog';
import ReportSection from '@/components/reports/report-section';
import TimelineChart from '@/components/reports/timeline-chart';
import { reportQuery, type ReportCard, type ReportFilters } from '@/components/reports/types';
import Stat from '@/components/stat';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dateTime, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface PageProps {
    filters: ReportFilters;
    editions: number[];
    sections: { key: string; title: string }[];
    summary: { participants: number; orders: number; activated: number; returning: number; countries: number; trips: number };
    timeline: { date: string; participants: number }[];
    today: string;
    lastSync: string | null;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reportes', href: '/reports' }];

const inputClass =
    'border-input bg-background focus-visible:ring-ring flex h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

function shift(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

export default function Reports() {
    const { filters, editions, sections, summary, timeline, today, lastSync } = usePage<PageProps>().props;
    const [picked, setPicked] = useState<{ section: string; card: string; label: string } | null>(null);

    const presets: { label: string; desde: string | null; hasta: string | null }[] = [
        { label: 'Todo', desde: null, hasta: null },
        { label: 'Hoy', desde: today, hasta: today },
        { label: 'Ayer', desde: shift(today, -1), hasta: shift(today, -1) },
        { label: 'Últimos 7 días', desde: shift(today, -6), hasta: today },
        { label: 'Este mes', desde: `${today.slice(0, 8)}01`, hasta: today },
    ];

    const apply = (next: Partial<ReportFilters>) => {
        const merged = { ...filters, ...next };
        router.get(`/reports?${reportQuery(merged)}`, {}, { preserveScroll: true, replace: true });
    };

    const pct = (n: number) => (summary.participants ? `${Math.round((n / summary.participants) * 100)}%` : '—');
    // Sections remount (and reload lazily) whenever a filter changes.
    const filterKey = reportQuery(filters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reportes" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={`Reportes de la feria ${filters.anio}`}
                    description={`Inscripciones pagas sincronizadas del plugin y activaciones del check-in.${lastSync ? ` Actualizado: ${dateTime.format(new Date(lastSync))}.` : ''}`}
                />

                <div className="flex flex-wrap items-end gap-3" role="group" aria-label="Filtros del reporte">
                    <label className="space-y-1 text-xs">
                        <span className="text-muted-foreground block">Edición</span>
                        <select
                            className={inputClass}
                            value={filters.anio}
                            onChange={(e) => apply({ anio: Number(e.target.value), desde: null, hasta: null })}
                        >
                            {editions.map((y) => (
                                <option key={y} value={y}>
                                    {y}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex flex-wrap gap-1">
                        {presets.map((p) => {
                            const active = p.desde === filters.desde && p.hasta === filters.hasta;
                            return (
                                <Button
                                    key={p.label}
                                    size="sm"
                                    variant={active ? 'default' : 'outline'}
                                    aria-pressed={active}
                                    onClick={() => apply({ desde: p.desde, hasta: p.hasta })}
                                >
                                    {p.label}
                                </Button>
                            );
                        })}
                    </div>
                    <label className="space-y-1 text-xs">
                        <span className="text-muted-foreground block">Comprado desde</span>
                        <input
                            type="date"
                            className={inputClass}
                            value={filters.desde ?? ''}
                            max={filters.hasta ?? undefined}
                            onChange={(e) => apply({ desde: e.target.value || null })}
                        />
                    </label>
                    <label className="space-y-1 text-xs">
                        <span className="text-muted-foreground block">hasta</span>
                        <input
                            type="date"
                            className={inputClass}
                            value={filters.hasta ?? ''}
                            min={filters.desde ?? undefined}
                            onChange={(e) => apply({ hasta: e.target.value || null })}
                        />
                    </label>
                </div>

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Stat label="Participantes" value={summary.participants} />
                    <Stat label="Pedidos pagados" value={summary.orders} />
                    <Stat
                        label="Activaron registro"
                        value={summary.activated}
                        display={`${number.format(summary.activated)} · ${pct(summary.activated)}`}
                    />
                    <Stat
                        label="Vinieron antes"
                        value={summary.returning}
                        display={`${number.format(summary.returning)} · ${pct(summary.returning)}`}
                    />
                    <Stat label="Países" value={summary.countries} />
                    <Stat label="Cupos en salidas" value={summary.trips} />
                </dl>

                <section className="space-y-3 rounded-xl border p-4" aria-labelledby="timeline-heading">
                    <h2 id="timeline-heading" className="text-base font-semibold">
                        Participantes nuevos por día
                    </h2>
                    <TimelineChart points={timeline} />
                </section>

                <div className="space-y-3" key={filterKey}>
                    {sections.map((s) => (
                        <ReportSection
                            key={s.key}
                            sectionKey={s.key}
                            title={s.title}
                            filters={filters}
                            defaultOpen={s.key === 'salidas'}
                            onPick={(card: ReportCard) => setPicked({ section: s.key, card: card.key, label: `${s.title}: ${card.label}` })}
                        />
                    ))}
                </div>
            </div>

            <PeopleDialog filters={filters} target={picked} onClose={() => setPicked(null)} />
        </AppLayout>
    );
}
