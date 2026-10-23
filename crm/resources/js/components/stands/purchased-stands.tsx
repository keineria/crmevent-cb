import Stat from '@/components/stat';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { dateTime, number } from '@/lib/format';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';

export type Flag = 'paid' | 'documents' | 'invoiced';

export interface PurchasedStand {
    key: string;
    sheet: string;
    zone: string | null;
    number: string;
    name: string;
    row: number;
    details: string[];
    paid: boolean;
    documents: boolean;
    invoiced: boolean;
    updated_by: string | null;
    updated_at: string | null;
}

interface Props {
    workbookId: number;
    columns: string[];
    stands: PurchasedStand[];
    flags: Record<Flag, string>;
    canEdit: boolean;
}

const normalize = (text: string) =>
    text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

/** Stands that already have a name in the sheet, with the team's follow-up: paid, documents, invoiced. */
export default function PurchasedStands({ workbookId, columns, stands, flags, canEdit }: Props) {
    const [query, setQuery] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [filter, setFilter] = useState<'' | `no-${Flag}`>('');

    const visible = useMemo(() => {
        const needle = normalize(query.trim());
        return stands.filter(
            (s) =>
                (!needle || normalize([s.number, s.name, s.zone ?? '', ...s.details].join(' ')).includes(needle)) &&
                (!filter || !s[filter.slice(3) as Flag]),
        );
    }, [stands, query, filter]);

    const toggle = (stand: PurchasedStand, flag: Flag, value: boolean) => {
        setPending(`${stand.key}:${flag}`);
        router.patch(
            `/stands/${workbookId}/comprados`,
            { key: stand.key, flag, value },
            { preserveScroll: true, preserveState: true, onFinish: () => setPending(null) },
        );
    };

    const count = (flag: Flag) => stands.filter((s) => s[flag]).length;

    if (stands.length === 0) {
        return (
            <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                Todavía no hay stands comprados. Aparecen aquí apenas se escribe un nombre en la columna «Nombre del Stand».
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Stands comprados" value={stands.length} display={number.format(stands.length)} />
                {(Object.keys(flags) as Flag[]).map((flag) => (
                    <Stat
                        key={flag}
                        label={flags[flag]}
                        value={count(flag)}
                        display={`${number.format(count(flag))} de ${number.format(stands.length)}`}
                    />
                ))}
            </dl>

            <div className="flex flex-wrap items-center gap-3">
                <label className="relative w-full sm:w-72">
                    <span className="sr-only">Buscar stand</span>
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                    <Input
                        type="search"
                        className="pl-9"
                        placeholder="Número, nombre o responsable"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </label>
                <label>
                    <span className="sr-only">Pendientes</span>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value as typeof filter)}
                    >
                        <option value="">Todos</option>
                        {(Object.keys(flags) as Flag[]).map((flag) => (
                            <option key={flag} value={`no-${flag}`}>
                                Falta: {flags[flag].toLowerCase()}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted text-muted-foreground text-left text-xs">
                        <tr>
                            <th scope="col" className="bg-muted sticky left-0 z-10 px-3 py-2 font-medium">
                                Stand
                            </th>
                            {(Object.keys(flags) as Flag[]).map((flag) => (
                                <th key={flag} scope="col" className="px-3 py-2 text-center font-medium whitespace-nowrap">
                                    {flags[flag]}
                                </th>
                            ))}
                            {columns.map((c) => (
                                <th key={c} scope="col" className="px-3 py-2 font-medium whitespace-nowrap">
                                    {c}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((s) => (
                            <tr key={s.key} className="border-t align-top">
                                <td className="bg-background sticky left-0 z-10 min-w-56 border-r px-3 py-2">
                                    <span className="font-medium">
                                        <span className="bg-muted mr-2 inline-block rounded px-1.5 text-xs tabular-nums">{s.number}</span>
                                        {s.name}
                                    </span>
                                    {(s.zone || (s.updated_by && s.updated_at)) && (
                                        <span className="text-muted-foreground block text-xs">
                                            {[
                                                s.zone,
                                                s.updated_by && s.updated_at ? `${s.updated_by} · ${dateTime.format(new Date(s.updated_at))}` : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    )}
                                </td>
                                {(Object.keys(flags) as Flag[]).map((flag) => (
                                    <td key={flag} className="px-3 py-2 text-center">
                                        <Checkbox
                                            aria-label={`${flags[flag]} · ${s.name}`}
                                            checked={s[flag]}
                                            disabled={!canEdit || pending === `${s.key}:${flag}`}
                                            onCheckedChange={(v) => toggle(s, flag, v === true)}
                                        />
                                    </td>
                                ))}
                                {s.details.map((d, i) => (
                                    <td key={i} className="px-3 py-2 whitespace-nowrap">
                                        {d}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {visible.length === 0 && (
                            <tr>
                                <td colSpan={columns.length + 4} className="text-muted-foreground px-3 py-6 text-center">
                                    Ningún stand coincide.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
