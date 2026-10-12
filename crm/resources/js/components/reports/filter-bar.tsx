import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { reportQuery, type ReportFilters } from './types';

const inputClass =
    'border-input bg-background focus-visible:ring-ring flex h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

function shift(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

/**
 * Edition and purchase-date filters, shared by the overview and every category page.
 */
export default function FilterBar({ path, filters, editions, today }: { path: string; filters: ReportFilters; editions: number[]; today: string }) {
    const presets: { label: string; desde: string | null; hasta: string | null }[] = [
        { label: 'Todo', desde: null, hasta: null },
        { label: 'Hoy', desde: today, hasta: today },
        { label: 'Ayer', desde: shift(today, -1), hasta: shift(today, -1) },
        { label: 'Últimos 7 días', desde: shift(today, -6), hasta: today },
        { label: 'Este mes', desde: `${today.slice(0, 8)}01`, hasta: today },
    ];

    const apply = (next: Partial<ReportFilters>) => {
        router.get(`${path}?${reportQuery({ ...filters, ...next })}`, {}, { preserveScroll: true, replace: true });
    };

    return (
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
    );
}
