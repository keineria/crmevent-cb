import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

export interface PeriodFilters {
    edicion: number;
    desde: string | null;
    hasta: string | null;
}

function shift(date: string, days: number): string {
    const [y, m, d] = date.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

function monthRange(month: string): [string, string] {
    const [y, m] = month.split('-').map(Number);
    return [`${month}-01`, new Date(Date.UTC(y, m, 0)).toISOString().slice(0, 10)];
}

const monthName = new Intl.DateTimeFormat('es-CO', { month: 'long', year: 'numeric', timeZone: 'UTC' });

/**
 * Edition, quick ranges (today, yesterday, last week, this month), a month picker and a custom range.
 * `today` comes from the server in Bogotá time so the presets match how sales are dated.
 */
export default function PeriodFilter({
    url,
    filters,
    editions,
    today,
    extra = {},
}: {
    url: string;
    filters: PeriodFilters;
    editions: number[];
    today: string;
    extra?: Record<string, string | number | null | undefined>;
}) {
    const [range, setRange] = useState({ desde: filters.desde ?? '', hasta: filters.hasta ?? '' });

    const go = (next: Partial<PeriodFilters>) => {
        const merged = { ...extra, ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== undefined && v !== null && v !== ''));
        router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const presets: { label: string; desde: string | null; hasta: string | null }[] = [
        { label: 'Todo', desde: null, hasta: null },
        { label: 'Hoy', desde: today, hasta: today },
        { label: 'Ayer', desde: shift(today, -1), hasta: shift(today, -1) },
        { label: 'Últimos 7 días', desde: shift(today, -6), hasta: today },
        { label: 'Este mes', desde: `${today.slice(0, 7)}-01`, hasta: today },
    ];

    // The twelve months before the edition's fair, newest first; sales open months ahead.
    const months = Array.from({ length: 13 }, (_, i) => {
        const date = new Date(Date.UTC(filters.edicion, 9 - i, 1));
        return date.toISOString().slice(0, 7);
    });
    const activeMonth = months.find((m) => {
        const [from, to] = monthRange(m);
        return filters.desde === from && filters.hasta === to;
    });

    return (
        <div className="flex flex-col gap-3 rounded-xl border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <label className="w-28">
                    <span className="sr-only">Edición</span>
                    <select
                        className={selectClass}
                        value={filters.edicion}
                        onChange={(e) => go({ edicion: Number(e.target.value), desde: null, hasta: null })}
                    >
                        {editions.map((y) => (
                            <option key={y} value={y}>
                                {y}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="flex flex-wrap gap-1" role="group" aria-label="Periodo">
                    {presets.map((p) => {
                        const active = filters.desde === p.desde && filters.hasta === p.hasta;
                        return (
                            <Button
                                key={p.label}
                                size="sm"
                                variant={active ? 'default' : 'outline'}
                                aria-pressed={active}
                                onClick={() => {
                                    setRange({ desde: p.desde ?? '', hasta: p.hasta ?? '' });
                                    go({ desde: p.desde, hasta: p.hasta });
                                }}
                            >
                                {p.label}
                            </Button>
                        );
                    })}
                </div>
                <label className="w-44">
                    <span className="sr-only">Mes</span>
                    <select
                        className={cn(selectClass, 'capitalize')}
                        value={activeMonth ?? ''}
                        onChange={(e) => {
                            if (!e.target.value) return;
                            const [desde, hasta] = monthRange(e.target.value);
                            setRange({ desde, hasta });
                            go({ desde, hasta });
                        }}
                    >
                        <option value="">Elegir mes…</option>
                        {months.map((m) => (
                            <option key={m} value={m}>
                                {monthName.format(new Date(`${m}-01T00:00:00Z`))}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    go({ desde: range.desde || null, hasta: range.hasta || null });
                }}
            >
                <label className="grid gap-1 text-xs">
                    <span className="text-muted-foreground">Desde</span>
                    <Input
                        type="date"
                        value={range.desde}
                        max={range.hasta || undefined}
                        onChange={(e) => setRange({ ...range, desde: e.target.value })}
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    <span className="text-muted-foreground">Hasta</span>
                    <Input
                        type="date"
                        value={range.hasta}
                        min={range.desde || undefined}
                        onChange={(e) => setRange({ ...range, hasta: e.target.value })}
                    />
                </label>
                <Button type="submit" variant="secondary">
                    Aplicar rango
                </Button>
            </form>
        </div>
    );
}
