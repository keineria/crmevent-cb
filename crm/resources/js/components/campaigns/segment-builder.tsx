import { crmJson } from '@/components/checkin/types';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { day, number } from '@/lib/format';
import { LoaderCircle, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { type Segment } from './types';

export interface SegmentOptions {
    years: number[];
    activities: { id: number; name: string; type: string; date: string | null }[];
    products: Record<string, string>;
}

const TYPES: [string, string][] = [
    ['asistentes', 'Asistentes'],
    ['interesados', 'Interesados'],
    ['suscriptores', 'Suscriptores'],
];

const ACTIVITY_TYPES: Record<string, string> = { conference: 'Conferencia', birding: 'Salida', workshop: 'Taller', other: 'Otra' };

const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

/**
 * Filters that pick who receives a campaign, with a live count of people who would get it.
 * Only contacts who accept broadcasts are ever counted.
 */
export default function SegmentBuilder({ value, onChange, options }: { value: Segment; onChange: (s: Segment) => void; options: SegmentOptions }) {
    const [count, setCount] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [activityQuery, setActivityQuery] = useState('');

    const set = (patch: Partial<Segment>) => onChange({ ...value, ...patch });
    const toggle = <T,>(list: T[] | undefined, item: T, on: boolean) => (on ? [...(list ?? []), item] : (list ?? []).filter((x) => x !== item));

    const purchaseYears = useMemo(() => {
        const now = new Date().getFullYear();
        return [...new Set([now + 1, now, ...options.years])].sort((a, b) => b - a);
    }, [options.years]);

    const activities = useMemo(() => {
        const q = activityQuery.trim().toLowerCase();
        return options.activities.filter((a) => !q || a.name.toLowerCase().includes(q) || (value.actividades ?? []).includes(a.id));
    }, [options.activities, activityQuery, value.actividades]);

    const key = JSON.stringify(value);
    useEffect(() => {
        setLoading(true);
        const timer = setTimeout(() => {
            crmJson<{ count: number }>('/campaigns/segment/count', { segment: JSON.parse(key) })
                .then((r) => setCount(r.count))
                .catch(() => setCount(null))
                .finally(() => setLoading(false));
        }, 300);
        return () => clearTimeout(timer);
    }, [key]);

    return (
        <div className="grid gap-5">
            <div className="bg-primary/5 flex items-center gap-3 rounded-lg border p-3" aria-live="polite">
                <Users className="text-primary h-5 w-5" aria-hidden />
                <div className="text-sm">
                    {loading && count === null ? (
                        <LoaderCircle className="h-4 w-4 animate-spin" aria-label="Contando" />
                    ) : (
                        <>
                            <span className="text-lg font-semibold tabular-nums">{count === null ? '—' : number.format(count)}</span> personas
                            recibirán esta campaña
                        </>
                    )}
                    <div className="text-muted-foreground text-xs">Solo cuenta contactos activos (no desinscritos) con correo.</div>
                </div>
            </div>

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">Tipo de contacto</legend>
                <div className="flex flex-wrap gap-4">
                    {TYPES.map(([key, label]) => (
                        <CheckLabel
                            key={key}
                            id={`tipo-${key}`}
                            label={label}
                            checked={(value.tipos ?? []).includes(key)}
                            onChange={(on) => set({ tipos: toggle(value.tipos, key, on) })}
                        />
                    ))}
                </div>
                <p className="text-muted-foreground text-xs">Sin marcar ninguno, entran todos los tipos.</p>
            </fieldset>

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">Producto preferido</legend>
                <div className="flex flex-wrap gap-4">
                    {Object.entries(options.products ?? {}).map(([key, label]) => (
                        <CheckLabel
                            key={key}
                            id={`producto-${key}`}
                            label={label}
                            checked={(value.productos ?? []).includes(key)}
                            onChange={(on) => set({ productos: toggle(value.productos, key, on) })}
                        />
                    ))}
                </div>
                <p className="text-muted-foreground text-xs">Sin marcar ninguno, entran todos.</p>
            </fieldset>

            {options.years.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">Estuvieron en la feria de</legend>
                    <div className="flex flex-wrap gap-4">
                        {options.years.map((y) => (
                            <CheckLabel
                                key={y}
                                id={`anio-${y}`}
                                label={String(y)}
                                checked={(value.anios ?? []).includes(y)}
                                onChange={(on) => set({ anios: toggle(value.anios, y, on) })}
                            />
                        ))}
                    </div>
                </fieldset>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    id="min_visitas"
                    label="Fidelidad"
                    value={value.min_visitas ? String(value.min_visitas) : ''}
                    onChange={(v) => set({ min_visitas: v ? Number(v) : undefined })}
                    options={[
                        ['', 'Cualquiera'],
                        ['2', 'Fueron a 2 o más ferias'],
                        ['3', 'Fueron a 3 o más ferias'],
                        ['4', 'Fueron a 4 o más ferias'],
                    ]}
                />
                <SelectField
                    id="pais"
                    label="Procedencia"
                    value={value.pais ?? ''}
                    onChange={(v) => set({ pais: v || undefined })}
                    options={[
                        ['', 'Todas'],
                        ['colombia', 'Colombia'],
                        ['internacional', 'Fuera de Colombia'],
                    ]}
                />
                <SelectField
                    id="min_estrellas"
                    label="Interacción con los correos"
                    value={value.min_estrellas ? String(value.min_estrellas) : ''}
                    onChange={(v) => set({ min_estrellas: v ? Number(v) : undefined })}
                    options={[
                        ['', 'Cualquiera'],
                        ['5', '★★★★★ (5 estrellas)'],
                        ['4', '★★★★ o más'],
                        ['3', '★★★ o más'],
                        ['2', '★★ o más'],
                    ]}
                />
                <SelectField
                    id="compro"
                    label="Ya compraron en la edición"
                    value={value.compro ? String(value.compro) : ''}
                    onChange={(v) => set({ compro: v ? Number(v) : undefined })}
                    options={[['', 'Sin filtro'], ...purchaseYears.map((y): [string, string] => [String(y), String(y)])]}
                />
                <SelectField
                    id="no_compro"
                    label="Aún no compran en la edición"
                    value={value.no_compro ? String(value.no_compro) : ''}
                    onChange={(v) => set({ no_compro: v ? Number(v) : undefined })}
                    options={[['', 'Sin filtro'], ...purchaseYears.map((y): [string, string] => [String(y), String(y)])]}
                />
            </div>

            {options.activities.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Inscritos en alguna de estas actividades
                        {(value.actividades ?? []).length > 0 && (
                            <span className="text-muted-foreground font-normal"> · {value.actividades!.length} elegidas</span>
                        )}
                    </legend>
                    <Input type="search" placeholder="Buscar actividad" value={activityQuery} onChange={(e) => setActivityQuery(e.target.value)} />
                    <div className="max-h-56 overflow-y-auto rounded-md border p-2">
                        {activities.map((a) => (
                            <div key={a.id} className="hover:bg-muted/50 flex items-start gap-2 rounded px-2 py-1.5">
                                <Checkbox
                                    id={`act-${a.id}`}
                                    checked={(value.actividades ?? []).includes(a.id)}
                                    onCheckedChange={(on) => set({ actividades: toggle(value.actividades, a.id, on === true) })}
                                />
                                <Label htmlFor={`act-${a.id}`} className="grid cursor-pointer gap-0.5 font-normal">
                                    <span>{a.name}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {ACTIVITY_TYPES[a.type] ?? a.type} · {day(a.date)}
                                    </span>
                                </Label>
                            </div>
                        ))}
                        {activities.length === 0 && <p className="text-muted-foreground p-2 text-sm">Ninguna actividad coincide.</p>}
                    </div>
                </fieldset>
            )}
        </div>
    );
}

function CheckLabel({ id, label, checked, onChange }: { id: string; label: string; checked: boolean; onChange: (on: boolean) => void }) {
    return (
        <div className="flex items-center gap-2">
            <Checkbox id={id} checked={checked} onCheckedChange={(on) => onChange(on === true)} />
            <Label htmlFor={id} className="cursor-pointer font-normal">
                {label}
            </Label>
        </div>
    );
}

function SelectField({
    id,
    label,
    value,
    onChange,
    options,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: [string, string][];
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <select id={id} className={selectClass} value={value} onChange={(e) => onChange(e.target.value)}>
                {options.map(([v, text]) => (
                    <option key={v} value={v}>
                        {text}
                    </option>
                ))}
            </select>
        </div>
    );
}
