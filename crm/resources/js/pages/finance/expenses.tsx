import Flash from '@/components/campaigns/flash';
import ExpenseDialog, { type ActivityOption, type ExpenseRow } from '@/components/finance/expense-dialog';
import PeriodFilter, { type PeriodFilters, selectClass } from '@/components/finance/period-filter';
import Heading from '@/components/heading';
import Stat from '@/components/stat';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { day, money, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Filters extends PeriodFilters {
    q?: string;
    categoria?: string;
    actividad?: string;
    pago?: string;
}

interface PageProps {
    expenses: ExpenseRow[];
    totals: { amount: number; paid: number; pending: number };
    filters: Filters;
    editions: number[];
    activities: ActivityOption[];
    categories: Record<string, string>;
    modes: Record<string, string>;
    types: Record<string, string>;
    canEdit: boolean;
    today: string;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finanzas', href: '/finance' },
    { title: 'Gastos', href: '/finance/expenses' },
];

export default function Expenses() {
    const { expenses, totals, filters, editions, activities, categories, modes, types, canEdit, today } = usePage<PageProps>().props;
    const [q, setQ] = useState(filters.q ?? '');
    const [dialog, setDialog] = useState<{ open: boolean; expense: ExpenseRow | null }>({ open: false, expense: null });

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== undefined && v !== null && v !== ''));
        router.get('/finance/expenses', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const remove = (e: ExpenseRow) => {
        if (confirm(`¿Eliminar el gasto «${e.concept}»?`)) {
            router.delete(`/finance/expenses/${e.id}`, { preserveScroll: true });
        }
    };

    const selectedActivity = filters.actividad && filters.actividad !== 'otros' ? Number(filters.actividad) : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Gastos" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Gastos"
                        description="Costos de cada actividad y otros gastos de la feria. Los gastos por inscrito se multiplican por los inscritos pagos del periodo."
                    />
                    {canEdit && (
                        <Button onClick={() => setDialog({ open: true, expense: null })}>
                            <Plus aria-hidden />
                            Nuevo gasto
                        </Button>
                    )}
                </div>

                <Flash />

                <PeriodFilter
                    url="/finance/expenses"
                    filters={filters}
                    editions={editions}
                    today={today}
                    extra={{ q: filters.q, categoria: filters.categoria, actividad: filters.actividad, pago: filters.pago }}
                />

                <div className="grid gap-3 md:grid-cols-[1fr_12rem_16rem_13rem]">
                    <form
                        role="search"
                        className="relative"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ q });
                        }}
                    >
                        <label htmlFor="q" className="sr-only">
                            Buscar
                        </label>
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            id="q"
                            type="search"
                            className="pl-9"
                            placeholder="Concepto o proveedor"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </form>
                    <label>
                        <span className="sr-only">Categoría</span>
                        <select className={selectClass} value={filters.categoria ?? ''} onChange={(e) => apply({ categoria: e.target.value })}>
                            <option value="">Todas las categorías</option>
                            {Object.entries(categories).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">Actividad</span>
                        <select className={selectClass} value={filters.actividad ?? ''} onChange={(e) => apply({ actividad: e.target.value })}>
                            <option value="">Todas las actividades</option>
                            <option value="otros">Solo otros gastos</option>
                            {activities.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.date ? `${day(a.date)} · ` : ''}
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="sr-only">Pago</span>
                        <select className={selectClass} value={filters.pago ?? ''} onChange={(e) => apply({ pago: e.target.value })}>
                            <option value="">Pagados y pendientes</option>
                            <option value="pagados">Pagados</option>
                            <option value="pendientes">Por pagar</option>
                        </select>
                    </label>
                </div>

                <dl className="grid grid-cols-3 gap-3">
                    <Stat label={`Total · ${number.format(expenses.length)} gastos`} value={totals.amount} display={money.format(totals.amount)} />
                    <Stat label="Pagado" value={totals.paid} display={money.format(totals.paid)} />
                    <Stat label="Por pagar" value={totals.pending} display={money.format(totals.pending)} />
                </dl>

                {expenses.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        No hay gastos con estos filtros. {canEdit ? 'Registra el primero con «Nuevo gasto».' : ''}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[900px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    {['Fecha', 'Concepto', 'Actividad', 'Cálculo', 'Total', 'Estado', ''].map((h, i) => (
                                        <th key={i} scope="col" className={`px-4 py-3 font-medium whitespace-nowrap ${i === 4 ? 'text-right' : ''}`}>
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {expenses.map((e) => (
                                    <tr key={e.id} className="align-top">
                                        <td className="px-4 py-3 whitespace-nowrap">{day(e.spent_on)}</td>
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{e.concept}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {categories[e.category] ?? e.category}
                                                {e.supplier && ` · ${e.supplier}`}
                                            </div>
                                            {e.notes && <div className="text-muted-foreground mt-1 line-clamp-2 text-xs">{e.notes}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {e.activity ?? <span className="text-muted-foreground">Otros gastos</span>}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3 text-xs whitespace-nowrap tabular-nums">
                                            {e.mode === 'per_person'
                                                ? `${money.format(e.unit_amount)} × ${number.format(e.people ?? 0)} inscritos`
                                                : `${money.format(e.unit_amount)} × ${number.format(e.quantity)}`}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{money.format(e.amount)}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={e.paid ? 'secondary' : 'outline'}>{e.paid ? 'Pagado' : 'Por pagar'}</Badge>
                                        </td>
                                        <td className="px-2 py-2 text-right whitespace-nowrap">
                                            {canEdit && (
                                                <>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        aria-label={`Editar ${e.concept}`}
                                                        onClick={() => setDialog({ open: true, expense: e })}
                                                    >
                                                        <Pencil aria-hidden />
                                                    </Button>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        aria-label={`Eliminar ${e.concept}`}
                                                        onClick={() => remove(e)}
                                                    >
                                                        <Trash2 aria-hidden />
                                                    </Button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {canEdit && (
                <ExpenseDialog
                    open={dialog.open}
                    onOpenChange={(open) => setDialog((d) => ({ ...d, open }))}
                    expense={dialog.expense}
                    activityId={dialog.expense ? null : selectedActivity}
                    edition={filters.edicion}
                    editions={editions}
                    activities={activities}
                    categories={categories}
                    modes={modes}
                    types={types}
                    today={today}
                />
            )}
        </AppLayout>
    );
}
