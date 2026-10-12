import Flash from '@/components/campaigns/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { dateTime, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CircleX, Download, FileUp, LoaderCircle, Search, Trash2 } from 'lucide-react';
import { type CSSProperties, type KeyboardEvent, useMemo, useRef, useState } from 'react';

/** One cell as the server reads it from the Excel. */
interface Cell {
    /** Text shown, formatted as in Excel. */
    v: string;
    /** Value the edit box starts with. */
    e: string;
    /** Formula, when the cell is calculated. */
    f?: string;
    /** Kind: s text, n number, d date, b true/false. */
    k: 's' | 'n' | 'd' | 'b';
    /** The formula ended in an error. */
    x?: boolean;
    b?: boolean;
    bg?: string;
    a?: 'left' | 'center' | 'right';
    /** Index of the drop-down list the cell takes its value from. */
    l?: number;
}

interface Sheet {
    name: string;
    hidden: boolean;
    rows: (Cell | null)[][];
    widths: (number | null)[];
    merges: [number, number, number, number][];
    lists: string[][];
    truncated: boolean;
}

interface PageProps {
    workbook: { id: number; name: string; original_name: string; edits: number; editor: string | null; updated_at: string };
    sheets: Sheet[];
    canEdit: boolean;
    errors: Record<string, string>;
    [key: string]: unknown;
}

function columnName(index: number): string {
    let name = '';
    for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) {
        name = String.fromCharCode(65 + ((n - 1) % 26)) + name;
    }
    return name;
}

const normalize = (text: string) =>
    text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

export default function StandShow() {
    const { workbook, sheets, canEdit, errors } = usePage<PageProps>().props;
    const [active, setActive] = useState(() =>
        Math.max(
            0,
            sheets.findIndex((s) => !s.hidden),
        ),
    );
    const [query, setQuery] = useState('');
    const [editing, setEditingState] = useState<{ r: number; c: number; value: string } | null>(null);
    // Mirrors `editing` so a blur that fires after Enter or Tab does not save twice.
    const editingRef = useRef<{ r: number; c: number; value: string } | null>(null);
    const setEditing = (next: { r: number; c: number; value: string } | null) => {
        editingRef.current = next;
        setEditingState(next);
    };
    const [saving, setSaving] = useState<string | null>(null);
    const replaceInput = useRef<HTMLInputElement>(null);

    const sheet = sheets[Math.min(active, sheets.length - 1)];
    const columns = Math.max(1, ...sheet.rows.map((r) => r.length));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Stand comercial', href: '/stands' },
        { title: workbook.name, href: `/stands/${workbook.id}` },
    ];

    // Merged ranges: the top-left cell spans, the rest are skipped. Ignored while searching, since rows get hidden.
    const { spans, covered } = useMemo(() => {
        const spans = new Map<string, [number, number]>();
        const covered = new Set<string>();
        if (query) return { spans, covered };
        for (const [r, c, rows, cols] of sheet.merges) {
            spans.set(`${r}:${c}`, [rows, cols]);
            for (let i = r; i < r + rows; i++) {
                for (let j = c; j < c + cols; j++) {
                    if (i !== r || j !== c) covered.add(`${i}:${j}`);
                }
            }
        }
        return { spans, covered };
    }, [sheet, query]);

    // The first row stays as the header while searching.
    const visibleRows = useMemo(() => {
        const all = sheet.rows.map((_, i) => i);
        if (!query.trim()) return all;
        const needle = normalize(query.trim());
        return all.filter((i) => i === 0 || sheet.rows[i].some((cell) => cell && normalize(cell.v).includes(needle)));
    }, [sheet, query]);

    const editable = (cell: Cell | null) => canEdit && !cell?.f;

    const startEdit = (r: number, c: number) => {
        const cell = sheet.rows[r]?.[c] ?? null;
        if (!editable(cell) || covered.has(`${r}:${c}`)) return;
        setEditing({ r, c, value: cell?.e ?? '' });
    };

    const save = (move?: [number, number]) => {
        const current = editingRef.current;
        if (!current) return;
        const { r, c, value } = current;
        const cell = sheet.rows[r]?.[c] ?? null;
        setEditing(null);
        if (move) startEdit(r + move[0], c + move[1]);
        if (value === (cell?.e ?? '')) return;
        const coordinate = `${columnName(c)}${r + 1}`;
        setSaving(`${r}:${c}`);
        router.patch(
            `/stands/${workbook.id}/celda`,
            { sheet: active, cell: coordinate, value },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(null),
            },
        );
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement | HTMLSelectElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            save([1, 0]);
        } else if (e.key === 'Tab') {
            e.preventDefault();
            save([0, e.shiftKey ? -1 : 1]);
        } else if (e.key === 'Escape') {
            setEditing(null);
        }
    };

    const replace = (file: File | undefined) => {
        if (!file) return;
        if (!confirm(`¿Reemplazar «${workbook.name}» por ${file.name}? Los cambios hechos aquí se pierden si no están en ese archivo.`)) return;
        router.post(`/stands/${workbook.id}/reemplazar`, { file }, { forceFormData: true, preserveScroll: true });
    };

    const remove = () => {
        if (confirm(`¿Eliminar «${workbook.name}»? Descarga antes el Excel si quieres conservar los cambios.`)) {
            router.delete(`/stands/${workbook.id}`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={workbook.name} />

            <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-0.5">
                        <h2 className="truncate text-xl font-semibold tracking-tight">{workbook.name}</h2>
                        <p className="text-muted-foreground text-sm">
                            {workbook.original_name} · {number.format(workbook.edits)} {workbook.edits === 1 ? 'cambio' : 'cambios'} · actualizado{' '}
                            {dateTime.format(new Date(workbook.updated_at))}
                            {workbook.editor ? ` por ${workbook.editor}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={`/stands/${workbook.id}/descargar`}>
                                <Download aria-hidden />
                                Descargar Excel
                            </a>
                        </Button>
                        {canEdit && (
                            <>
                                <input
                                    ref={replaceInput}
                                    type="file"
                                    accept=".xlsx,.xls,.ods"
                                    className="sr-only"
                                    tabIndex={-1}
                                    aria-label="Archivo para reemplazar"
                                    onChange={(e) => {
                                        replace(e.target.files?.[0]);
                                        e.target.value = '';
                                    }}
                                />
                                <Button variant="outline" onClick={() => replaceInput.current?.click()}>
                                    <FileUp aria-hidden />
                                    Reemplazar
                                </Button>
                                <Button variant="ghost" onClick={remove} aria-label="Eliminar Excel">
                                    <Trash2 aria-hidden />
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <Flash />
                {(errors.value || errors.cell || errors.file) && (
                    <p role="alert" className="text-destructive flex items-center gap-2 text-sm">
                        <CircleX className="size-4" aria-hidden />
                        {errors.value || errors.cell || errors.file}
                    </p>
                )}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="tablist" aria-label="Hojas" className="bg-muted flex max-w-full gap-1 overflow-x-auto rounded-lg p-1">
                        {sheets.map((s, i) => (
                            <button
                                key={i}
                                role="tab"
                                aria-selected={i === active}
                                onClick={() => {
                                    setActive(i);
                                    setEditing(null);
                                }}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm whitespace-nowrap transition-colors',
                                    i === active ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {s.name}
                                {s.hidden && <span className="text-muted-foreground ml-1 text-xs">(oculta)</span>}
                            </button>
                        ))}
                    </div>
                    <label className="relative w-full sm:w-72">
                        <span className="sr-only">Buscar en la hoja</span>
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            type="search"
                            className="pl-9"
                            placeholder="Buscar en la hoja"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </label>
                </div>

                <p className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                    <span className="inline-flex items-center gap-1.5">
                        <span className="bg-muted inline-flex h-4 items-center rounded px-1 font-mono text-[10px] italic">fx</span>
                        Fórmula: se calcula sola
                    </span>
                    {canEdit && <span>Haz clic en una celda para editarla. Enter guarda y baja, Tab guarda y pasa a la derecha, Esc cancela.</span>}
                    {sheet.truncated && <span>Esta hoja es muy grande: se muestran las primeras filas y columnas.</span>}
                </p>

                <div className="max-h-[70vh] overflow-auto rounded-lg border">
                    <table className="border-separate border-spacing-0 text-sm">
                        <thead>
                            <tr>
                                <th className="bg-muted sticky top-0 left-0 z-30 w-12 min-w-12 border-r border-b" aria-label="Fila" />
                                {Array.from({ length: columns }, (_, c) => (
                                    <th
                                        key={c}
                                        scope="col"
                                        className="bg-muted text-muted-foreground sticky top-0 z-20 border-r border-b px-2 py-1 text-center text-xs font-medium"
                                        style={{ minWidth: Math.max(sheet.widths[c] ?? 96, 56) }}
                                    >
                                        {columnName(c)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {visibleRows.map((r) => (
                                <tr key={r}>
                                    <th
                                        scope="row"
                                        className="bg-muted text-muted-foreground sticky left-0 z-10 border-r border-b px-2 py-1 text-right text-xs font-medium"
                                    >
                                        {r + 1}
                                    </th>
                                    {Array.from({ length: columns }, (_, c) => {
                                        const key = `${r}:${c}`;
                                        if (covered.has(key)) return null;
                                        const cell = sheet.rows[r]?.[c] ?? null;
                                        const span = spans.get(key);
                                        const isEditing = editing?.r === r && editing.c === c;
                                        const canChange = editable(cell);
                                        const coordinate = `${columnName(c)}${r + 1}`;
                                        const style: CSSProperties = cell?.bg && !isEditing ? { backgroundColor: cell.bg, color: '#111827' } : {};
                                        const align = cell?.a ?? (cell && cell.k !== 's' ? 'right' : 'left');

                                        return (
                                            <td
                                                key={c}
                                                rowSpan={span?.[0]}
                                                colSpan={span?.[1]}
                                                style={style}
                                                title={cell?.f ? `${coordinate}: ${cell.f}` : undefined}
                                                className={cn(
                                                    'relative h-8 border-r border-b px-2 py-1 whitespace-nowrap',
                                                    cell?.b && 'font-semibold',
                                                    cell?.f && !cell.bg && 'bg-muted/60',
                                                    cell?.x && 'text-destructive',
                                                    canChange && !isEditing && 'hover:ring-primary/50 cursor-cell hover:ring-1 hover:ring-inset',
                                                    saving === key && 'opacity-50',
                                                    isEditing && 'p-0',
                                                )}
                                                onClick={() => !isEditing && startEdit(r, c)}
                                            >
                                                {isEditing ? (
                                                    cell?.l !== undefined ? (
                                                        <select
                                                            autoFocus
                                                            aria-label={`Celda ${coordinate}`}
                                                            className="bg-background ring-primary h-8 w-full px-2 ring-2 outline-none ring-inset"
                                                            value={editing.value}
                                                            onChange={(e) => setEditing({ ...editing, value: e.target.value })}
                                                            onBlur={() => save()}
                                                            onKeyDown={onKeyDown}
                                                        >
                                                            <option value="" />
                                                            {sheet.lists[cell.l].map((o) => (
                                                                <option key={o} value={o}>
                                                                    {o}
                                                                </option>
                                                            ))}
                                                            {editing.value && !sheet.lists[cell.l].includes(editing.value) && (
                                                                <option value={editing.value}>{editing.value}</option>
                                                            )}
                                                        </select>
                                                    ) : (
                                                        <input
                                                            autoFocus
                                                            aria-label={`Celda ${coordinate}`}
                                                            className="bg-background ring-primary h-8 w-full min-w-full px-2 ring-2 outline-none ring-inset"
                                                            style={{ textAlign: align }}
                                                            value={editing.value}
                                                            placeholder={cell?.k === 'd' ? 'AAAA-MM-DD' : undefined}
                                                            onChange={(e) => setEditing({ ...editing, value: e.target.value })}
                                                            onBlur={() => save()}
                                                            onKeyDown={onKeyDown}
                                                        />
                                                    )
                                                ) : (
                                                    <span
                                                        className="flex items-center gap-1"
                                                        style={{
                                                            justifyContent:
                                                                align === 'right' ? 'flex-end' : align === 'center' ? 'center' : 'flex-start',
                                                        }}
                                                    >
                                                        {cell?.f && (
                                                            <span className="text-muted-foreground font-mono text-[10px] italic" aria-hidden>
                                                                fx
                                                            </span>
                                                        )}
                                                        {saving === key ? (
                                                            <LoaderCircle className="size-3 animate-spin" aria-label="Guardando" />
                                                        ) : (
                                                            cell?.v
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
