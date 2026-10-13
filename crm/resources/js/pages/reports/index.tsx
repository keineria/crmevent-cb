import Heading from '@/components/heading';
import DonutChart from '@/components/reports/donut-chart';
import FilterBar from '@/components/reports/filter-bar';
import TimelineChart from '@/components/reports/timeline-chart';
import { reportQuery, upperFirst, type ReportFilters } from '@/components/reports/types';
import Stat from '@/components/stat';
import { nps, percent, satisfaction, type SurveyTotals } from '@/components/surveys/types';
import AppLayout from '@/layouts/app-layout';
import { dateTime, day, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { type ReactNode } from 'react';

interface Count {
    label: string;
    count: number;
}

type Category =
    | {
          key: 'salidas';
          title: string;
          summary: {
              sold: number;
              capacity: number;
              available: number;
              outings: number;
              days: { date: string | null; sold: number; available: number; outings: number }[];
          };
      }
    | { key: 'actividades'; title: string; summary: { items: Count[] } }
    | { key: 'demograficos'; title: string; summary: { countries: number; top: Count[]; sex: Count[] } }
    | { key: 'logistica'; title: string; summary: { sizes: Count[]; meals: Count[] } }
    | { key: 'activacion'; title: string; summary: { activated: number; total: number } };

interface PageProps {
    filters: ReportFilters;
    editions: number[];
    categories: Category[];
    summary: { participants: number; orders: number; activated: number; returning: number; countries: number; trips: number };
    timeline: { date: string; participants: number }[];
    surveys: SurveyTotals & { surveys: number };
    today: string;
    lastSync: string | null;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reportes', href: '/reports' }];

export default function Reports() {
    const { filters, editions, categories, summary, timeline, surveys, today, lastSync } = usePage<PageProps>().props;
    const pct = (n: number, of = summary.participants) => (of ? `${Math.round((n / of) * 100)}%` : '—');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reportes" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={`Reportes de la feria ${filters.anio}`}
                    description={`Inscripciones pagas sincronizadas del plugin, asistentes importados de ferias anteriores y activaciones del check-in.${lastSync ? ` Actualizado: ${dateTime.format(new Date(lastSync))}.` : ''}`}
                />

                <FilterBar path="/reports" filters={filters} editions={editions} today={today} />

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

                <div className="grid gap-4 lg:grid-cols-2">
                    {categories.map((c) => (
                        <CategoryCard key={c.key} category={c} href={`/reports/categoria/${c.key}?${reportQuery(filters)}`}>
                            <CategoryBody category={c} pct={pct} />
                        </CategoryCard>
                    ))}
                    <section className="flex flex-col rounded-xl border" aria-labelledby="cat-encuestas">
                        <Link
                            href={`/reports/encuestas?anio=${filters.anio}`}
                            className="hover:bg-muted/40 flex items-center justify-between gap-4 rounded-t-xl px-4 py-3"
                        >
                            <h2 id="cat-encuestas" className="text-base font-semibold">
                                Encuestas de satisfacción
                            </h2>
                            <span className="text-muted-foreground flex items-center gap-1 text-sm">
                                Ver todo
                                <ChevronRight className="size-4" aria-hidden />
                            </span>
                        </Link>
                        <div className="flex-1 border-t p-4">
                            {surveys.surveys === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Aún no hay encuestas de esta feria. Salen solas cuando termina cada actividad.
                                </p>
                            ) : (
                                <dl className="grid grid-cols-3 gap-3">
                                    <Figure label="Satisfacción" value={satisfaction(surveys.satisfaction)} />
                                    <Figure label="NPS" value={nps(surveys.nps)} />
                                    <Figure
                                        label={`Respuestas de ${number.format(surveys.sent)}`}
                                        value={`${number.format(surveys.responded)} · ${percent(surveys.rate)}`}
                                    />
                                </dl>
                            )}
                        </div>
                    </section>
                </div>

                <section className="space-y-3 rounded-xl border p-4" aria-labelledby="timeline-heading">
                    <h2 id="timeline-heading" className="text-base font-semibold">
                        Participantes nuevos por día
                    </h2>
                    <TimelineChart points={timeline} />
                </section>
            </div>
        </AppLayout>
    );
}

function CategoryCard({ category, href, children }: { category: Category; href: string; children: ReactNode }) {
    return (
        <section className="flex flex-col rounded-xl border" aria-labelledby={`cat-${category.key}`}>
            <Link href={href} className="hover:bg-muted/40 flex items-center justify-between gap-4 rounded-t-xl px-4 py-3">
                <h2 id={`cat-${category.key}`} className="text-base font-semibold">
                    {category.title}
                </h2>
                <span className="text-muted-foreground flex items-center gap-1 text-sm">
                    Ver todo
                    <ChevronRight className="size-4" aria-hidden />
                </span>
            </Link>
            <div className="flex-1 border-t p-4">{children}</div>
        </section>
    );
}

function CategoryBody({ category, pct }: { category: Category; pct: (n: number, of?: number) => string }) {
    switch (category.key) {
        case 'salidas': {
            const s = category.summary;
            return (
                <div className="space-y-4">
                    <dl className="grid grid-cols-3 gap-3">
                        <Figure label="Cupos vendidos" value={number.format(s.sold)} />
                        <Figure label="Cupos libres" value={number.format(s.available)} />
                        <Figure label="Salidas" value={number.format(s.outings)} />
                    </dl>
                    {s.days.length > 0 && (
                        <ul className="divide-y text-sm">
                            {s.days.map((d) => (
                                <li key={d.date ?? 'sin-fecha'} className="flex justify-between gap-4 py-1.5">
                                    <span>{d.date ? upperFirst(day(d.date)) : 'Sin fecha'}</span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {number.format(d.sold)} vendidos · {number.format(d.available)} libres
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            );
        }
        case 'actividades':
            return <Bars items={category.summary.items} />;
        case 'demograficos': {
            const s = category.summary;
            const knownSex = s.sex.filter((x) => x.label !== 'Sin dato');
            return (
                <div className="grid gap-6 sm:grid-cols-2">
                    <div className="space-y-2">
                        <h3 className="text-muted-foreground text-xs font-medium">Top 5 de {number.format(s.countries)} países</h3>
                        <Bars items={s.top} />
                    </div>
                    <div className="space-y-2">
                        <h3 className="text-muted-foreground text-xs font-medium">Sexo</h3>
                        {knownSex.length > 0 ? (
                            <DonutChart label="Participantes por sexo" slices={s.sex} />
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                Aún sin dato: el plugin no pregunta el sexo; solo lo tenemos de quienes vinieron a ferias anteriores.
                            </p>
                        )}
                    </div>
                </div>
            );
        }
        case 'logistica':
            return (
                <div className="grid gap-6 sm:grid-cols-2">
                    <div className="space-y-2">
                        <h3 className="text-muted-foreground text-xs font-medium">Tallas de camiseta</h3>
                        <Bars items={category.summary.sizes} />
                    </div>
                    <div className="space-y-2">
                        <h3 className="text-muted-foreground text-xs font-medium">Alimentación</h3>
                        <Bars items={category.summary.meals} />
                    </div>
                </div>
            );
        case 'activacion': {
            const s = category.summary;
            const share = s.total ? Math.round((s.activated / s.total) * 100) : 0;
            return (
                <div className="space-y-3">
                    <p className="text-2xl font-semibold tabular-nums">
                        {number.format(s.activated)} <span className="text-muted-foreground text-base font-normal">de {number.format(s.total)}</span>
                    </p>
                    <div className="bg-muted h-2 overflow-hidden rounded-full" aria-hidden>
                        <div className="bg-primary h-full rounded-full" style={{ width: `${share}%` }} />
                    </div>
                    <p className="text-muted-foreground text-sm">{pct(s.activated, s.total)} ya activó su registro en la entrada o en una salida.</p>
                </div>
            );
        }
    }
}

function Figure({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

/** Horizontal bars for a short ranked list; one hue, the number printed beside each bar. */
function Bars({ items }: { items: Count[] }) {
    if (items.length === 0) return <p className="text-muted-foreground text-sm">Sin datos para estos filtros.</p>;
    const max = Math.max(1, ...items.map((i) => i.count));
    return (
        <ul className="space-y-2 text-sm">
            {items.map((i) => (
                <li key={i.label} className="grid grid-cols-[minmax(0,8rem)_1fr_auto] items-center gap-3">
                    <span className="truncate" title={i.label}>
                        {i.label}
                    </span>
                    <span className="bg-muted h-2 overflow-hidden rounded-full" aria-hidden>
                        <span className="bg-primary block h-full rounded-full" style={{ width: `${(i.count / max) * 100}%` }} />
                    </span>
                    <span className="tabular-nums">{number.format(i.count)}</span>
                </li>
            ))}
        </ul>
    );
}
