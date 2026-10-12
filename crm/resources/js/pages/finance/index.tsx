import Flash from '@/components/campaigns/flash';
import { DonutChart, LineChart, SERIES } from '@/components/finance/charts';
import ExpenseDialog, { type ActivityOption } from '@/components/finance/expense-dialog';
import PeriodFilter, { type PeriodFilters, selectClass } from '@/components/finance/period-filter';
import Heading from '@/components/heading';
import Stat from '@/components/stat';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dateTime, day, money, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus, Receipt, TrendingDown, TrendingUp } from 'lucide-react';
import { useMemo, useState } from 'react';

interface ActivityRow {
    id: number;
    name: string;
    type: string;
    date: string | null;
    start_time: string | null;
    price: number;
    capacity: number;
    participants: number;
    paying: number;
    courtesies: number;
    sales: number;
    discounts: number;
    fixed_costs: number;
    variable_costs: number;
    costs: number;
    cost_lines: number;
    profit: number;
    margin: number | null;
    break_even: number | null;
}

interface PageProps {
    kpis: {
        income: number;
        activity_sales: number;
        adjustments: number;
        costs: number;
        activity_costs: number;
        other_costs: number;
        unpaid_costs: number;
        profit: number;
        margin: number | null;
        orders: number;
        participants: number;
        seats: number;
        courtesies: number;
        courtesy_value: number;
        discounts: number;
    };
    activities: ActivityRow[];
    by_type: { type: string; label: string; sales: number; seats: number }[];
    by_category: { category: string; label: string; amount: number }[];
    series: { date: string; income: number; costs: number }[];
    filters: PeriodFilters;
    editions: number[];
    types: Record<string, string>;
    categories: Record<string, string>;
    modes: Record<string, string>;
    canEdit: boolean;
    today: string;
    lastSync: string | null;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Finanzas', href: '/finance' }];

type Sort = 'date' | 'profit' | 'sales' | 'costs';

export function Profit({ value, className }: { value: number; className?: string }) {
    const Icon = value < 0 ? TrendingDown : TrendingUp;
    return (
        <span className={cn('inline-flex items-center gap-1 tabular-nums', value < 0 ? 'text-destructive' : value > 0 && 'text-success', className)}>
            {value !== 0 && <Icon className="size-3.5 shrink-0" aria-hidden />}
            <span className="sr-only">{value < 0 ? 'Pérdida' : 'Ganancia'}</span>
            {money.format(value)}
        </span>
    );
}

export default function FinanceIndex() {
    const { kpis, activities, by_type, by_category, series, filters, editions, types, categories, modes, canEdit, today, lastSync } =
        usePage<PageProps>().props;
    const [view, setView] = useState<'daily' | 'cumulative'>('cumulative');
    const [type, setType] = useState<string | null>(null);
    const [sort, setSort] = useState<Sort>('date');
    const [dialog, setDialog] = useState<{ open: boolean; activityId: number | null }>({ open: false, activityId: null });

    const lines = useMemo(() => {
        let income = 0;
        let costs = 0;
        const cumulative = view === 'cumulative';
        return [
            {
                key: 'income',
                label: 'Ingresos',
                color: SERIES[0],
                values: series.map((p) => (cumulative ? (income += p.income) : p.income)),
            },
            {
                key: 'costs',
                label: 'Gastos',
                color: SERIES[1],
                values: series.map((p) => (cumulative ? (costs += p.costs) : p.costs)),
            },
        ];
    }, [series, view]);

    const rows = useMemo(() => {
        const list = activities.filter((a) => !type || a.type === type);
        if (sort === 'date') return list;
        return [...list].sort((a, b) => (sort === 'profit' ? a.profit - b.profit : b[sort] - a[sort]));
    }, [activities, type, sort]);

    const activityOptions: ActivityOption[] = activities.map((a) => ({ id: a.id, name: a.name, type: a.type, date: a.date }));
    const rangeText = filters.desde
        ? filters.desde === filters.hasta
            ? day(filters.desde)
            : `${day(filters.desde)} – ${day(filters.hasta)}`
        : `toda la edición ${filters.edicion}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finanzas" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Finanzas"
                        description={`Ventas del plugin y costos registrados aquí, para ${rangeText}.${lastSync ? ` Ventas al ${dateTime.format(new Date(lastSync))}.` : ''}`}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={`/finance/expenses?edicion=${filters.edicion}`}>
                                <Receipt aria-hidden />
                                Gastos
                            </Link>
                        </Button>
                        {canEdit && (
                            <Button onClick={() => setDialog({ open: true, activityId: null })}>
                                <Plus aria-hidden />
                                Nuevo gasto
                            </Button>
                        )}
                    </div>
                </div>

                <Flash />

                <PeriodFilter url="/finance" filters={filters} editions={editions} today={today} />

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label={`Ingresos · ${number.format(kpis.orders)} pedidos pagados`}
                        value={kpis.income}
                        display={money.format(kpis.income)}
                    />
                    <Stat label="Gastos" value={kpis.costs} display={money.format(kpis.costs)} />
                    <div className="rounded-xl border p-4">
                        <dt className="text-muted-foreground text-xs">
                            {kpis.profit < 0 ? 'Pérdida' : 'Utilidad'}
                            {kpis.margin !== null && ` · margen ${kpis.margin} %`}
                        </dt>
                        <dd className="mt-1 text-2xl font-semibold">
                            <Profit value={kpis.profit} />
                        </dd>
                    </div>
                    <Stat label="Gastos por pagar" value={kpis.unpaid_costs} display={money.format(kpis.unpaid_costs)} />
                </dl>
                <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Participantes pagos" value={kpis.participants} />
                    <Stat label="Cupos (sin cortesías)" value={kpis.seats - kpis.courtesies} />
                    <Stat label={`Cortesías · ${money.format(kpis.courtesy_value)} en precio`} value={kpis.courtesies} />
                    <Stat label="Descuentos dados" value={kpis.discounts} display={money.format(kpis.discounts)} />
                </dl>

                <section className="viz-root rounded-xl border p-4" aria-labelledby="flow-title">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 id="flow-title" className="font-medium">
                            Ingresos y gastos {view === 'cumulative' ? 'acumulados' : 'por día'}
                        </h2>
                        <div className="flex gap-1" role="group" aria-label="Vista">
                            {(['cumulative', 'daily'] as const).map((v) => (
                                <Button
                                    key={v}
                                    size="sm"
                                    variant={view === v ? 'default' : 'outline'}
                                    aria-pressed={view === v}
                                    onClick={() => setView(v)}
                                >
                                    {v === 'cumulative' ? 'Acumulado' : 'Por día'}
                                </Button>
                            ))}
                        </div>
                    </div>
                    <div className="mb-2 flex gap-4 text-xs">
                        {lines.map((l) => (
                            <span key={l.key} className="flex items-center gap-1.5">
                                <span className="h-0.5 w-4 rounded" style={{ background: l.color }} aria-hidden />
                                {l.label}
                            </span>
                        ))}
                    </div>
                    <LineChart dates={series.map((p) => p.date)} series={lines} />
                    {series.length > 0 && (
                        <details className="mt-2 text-sm">
                            <summary className="text-muted-foreground cursor-pointer text-xs">Ver los datos en tabla</summary>
                            <div className="mt-2 max-h-64 overflow-auto">
                                <table className="w-full text-xs">
                                    <thead className="text-muted-foreground text-left">
                                        <tr>
                                            <th className="py-1 font-medium">Día</th>
                                            <th className="py-1 text-right font-medium">Ingresos</th>
                                            <th className="py-1 text-right font-medium">Gastos</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {series
                                            .filter((p) => p.income || p.costs)
                                            .map((p) => (
                                                <tr key={p.date}>
                                                    <td className="py-1">{day(p.date)}</td>
                                                    <td className="py-1 text-right tabular-nums">{money.format(p.income)}</td>
                                                    <td className="py-1 text-right tabular-nums">{money.format(p.costs)}</td>
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    )}
                </section>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="rounded-xl border p-4" aria-labelledby="types-title">
                        <h2 id="types-title" className="font-medium">
                            Ventas por tipo de actividad
                        </h2>
                        <p className="text-muted-foreground mb-3 text-xs">Toca un tipo para filtrar la tabla de actividades.</p>
                        <DonutChart
                            centerLabel="Ventas"
                            emptyText="Sin ventas en este periodo."
                            slices={by_type.map((t) => ({ key: t.type, label: t.label, value: t.sales, detail: `${number.format(t.seats)} cupos` }))}
                            selected={type}
                            onSelect={setType}
                        />
                    </section>
                    <section className="rounded-xl border p-4" aria-labelledby="cat-title">
                        <h2 id="cat-title" className="font-medium">
                            Gastos por categoría
                        </h2>
                        <p className="text-muted-foreground mb-3 text-xs">
                            {money.format(kpis.activity_costs)} de actividades y {money.format(kpis.other_costs)} en otros gastos.
                        </p>
                        <DonutChart
                            centerLabel="Gastos"
                            emptyText="Aún no hay gastos registrados para este periodo."
                            slices={by_category.map((c) => ({ key: c.category, label: c.label, value: c.amount }))}
                        />
                    </section>
                </div>

                {Math.abs(kpis.adjustments) >= 1 && (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-3 text-sm">
                        Los pedidos suman {money.format(kpis.income)} y las actividades {money.format(kpis.activity_sales)}. La diferencia de{' '}
                        <span className="text-foreground font-medium tabular-nums">{money.format(kpis.adjustments)}</span> viene de cambios a
                        actividades más baratas (no hay reembolso), recargos o descuentos del pedido completo.
                    </p>
                )}

                <section aria-labelledby="act-title" className="space-y-3">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="act-title" className="font-medium">
                                Utilidad por actividad {type && `· ${types[type]}`}
                            </h2>
                            <p className="text-muted-foreground text-xs">
                                Punto de equilibrio: inscritos pagos que cubren los costos fijos con el precio promedio vendido.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            {type && (
                                <Button size="sm" variant="ghost" onClick={() => setType(null)}>
                                    Ver todos los tipos
                                </Button>
                            )}
                            <label className="w-48">
                                <span className="sr-only">Ordenar</span>
                                <select className={selectClass} value={sort} onChange={(e) => setSort(e.target.value as Sort)}>
                                    <option value="date">Por fecha</option>
                                    <option value="profit">Más pérdida primero</option>
                                    <option value="sales">Más ventas primero</option>
                                    <option value="costs">Más costos primero</option>
                                </select>
                            </label>
                        </div>
                    </div>

                    {rows.length === 0 ? (
                        <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                            No hay actividades de esta edición todavía. Llegan con la sincronización del plugin.
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[980px] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        {['Actividad', 'Inscritos', 'Ventas', 'Costos', 'Utilidad', 'Equilibrio', ''].map((h, i) => (
                                            <th
                                                key={i}
                                                scope="col"
                                                className={cn('px-4 py-3 font-medium whitespace-nowrap', i > 0 && i < 6 && 'text-right')}
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows.map((a) => (
                                        <tr key={a.id} className="align-top">
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/finance/expenses?edicion=${filters.edicion}&actividad=${a.id}`}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {a.name}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">
                                                    {types[a.type] ?? a.type}
                                                    {a.date && ` · ${day(a.date)}`}
                                                    {a.start_time && ` ${a.start_time}`}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {number.format(a.participants)}
                                                {a.capacity > 0 && <span className="text-muted-foreground"> / {number.format(a.capacity)}</span>}
                                                {a.courtesies > 0 && (
                                                    <div className="text-muted-foreground text-xs">
                                                        {number.format(a.courtesies)} {a.courtesies === 1 ? 'cortesía' : 'cortesías'}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {money.format(a.sales)}
                                                {a.discounts > 0 && (
                                                    <div className="text-muted-foreground text-xs">−{money.format(a.discounts)} desc.</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {a.cost_lines ? money.format(a.costs) : <span className="text-muted-foreground">Sin costos</span>}
                                                {a.variable_costs > 0 && (
                                                    <div className="text-muted-foreground text-xs">
                                                        {money.format(a.variable_costs)} según inscritos
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Profit value={a.profit} />
                                                {a.margin !== null && <div className="text-muted-foreground text-xs">{a.margin} %</div>}
                                            </td>
                                            <td className="px-4 py-3 text-right text-xs tabular-nums">
                                                {a.break_even === null ? (
                                                    <span className="text-muted-foreground">—</span>
                                                ) : a.paying >= a.break_even ? (
                                                    <span className="text-success">Cubierto ({number.format(a.break_even)})</span>
                                                ) : (
                                                    <span>
                                                        Faltan {number.format(a.break_even - a.paying)}
                                                        <span className="text-muted-foreground block">de {number.format(a.break_even)}</span>
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2 text-right">
                                                {canEdit && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        aria-label={`Agregar costo a ${a.name}`}
                                                        onClick={() => setDialog({ open: true, activityId: a.id })}
                                                    >
                                                        <Plus aria-hidden />
                                                        Costo
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            {canEdit && (
                <ExpenseDialog
                    open={dialog.open}
                    onOpenChange={(open) => setDialog((d) => ({ ...d, open }))}
                    activityId={dialog.activityId}
                    edition={filters.edicion}
                    editions={editions}
                    activities={activityOptions}
                    categories={categories}
                    modes={modes}
                    types={types}
                    today={today}
                />
            )}
        </AppLayout>
    );
}
