import Heading from '@/components/heading';
import QrScanDialog from '@/components/qr-scan-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { day, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CircleCheck, CircleX, LoaderCircle, Mail, QrCode, ScanLine, Search, Undo2 } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useRef, useState } from 'react';

type Mode = 'entrada' | 'salidas';

interface CheckInInfo {
    id: number;
    at: string;
    by: string | null;
    method: string;
}

interface DayActivity {
    id: number;
    name: string;
    type: string;
    destination: string | null;
    start_time: string | null;
    expected: number;
    checked_in: number;
}

interface Participant {
    id: number;
    name: string;
    document: string | null;
    email: string | null;
    whatsapp: string | null;
    entity: string | null;
    shirt_size: string | null;
    meal_type: string | null;
    allergies: string | null;
    code: string;
    qr_sent_at: string | null;
    order: { number: string; status: string; paid: boolean };
    activities: {
        id: number;
        name: string | null;
        type: string | null;
        date: string | null;
        start_time: string | null;
        active: boolean;
        on_date: boolean;
        check_in: CheckInInfo | null;
    }[];
}

interface RosterRow {
    participant: number;
    activity: number;
    name: string;
    document: string | null;
    whatsapp: string | null;
    meal_type: string | null;
    allergies: string | null;
    check_in: CheckInInfo | null;
}

interface PageProps {
    mode: Mode;
    modes: Mode[];
    date: string;
    today: string;
    dates: string[];
    activities: DayActivity[];
    trip: number | null;
    roster: RosterRow[] | null;
    q: string;
    results: { method: string; participants: Participant[] } | null;
    types: Record<string, string>;
    canManage: boolean;
    qr: { pending: number; queued: number; sent: number; per_hour: number } | null;
    flash: { success?: string; error?: string };
    [key: string]: unknown;
}

const MODE_LABELS: Record<Mode, string> = { entrada: 'Entrada', salidas: 'Salidas' };
const MEALS: Record<string, string> = { traditional: 'Tradicional', vegetarian: 'Vegetariana', vegan: 'Vegana' };
const time = new Intl.DateTimeFormat('es-CO', { hour: 'numeric', minute: '2-digit', timeZone: 'America/Bogota' });
const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

function arrival(c: CheckInInfo) {
    return `Ingresó ${time.format(new Date(c.at))}${c.by ? ` · ${c.by}` : ''}`;
}

export default function CheckInPage() {
    const page = usePage<PageProps>();
    const { mode, modes, date, today, dates, activities, trip, roster, q, results, types, canManage, qr, flash } = page.props;
    const [term, setTerm] = useState(q);
    const [scanning, setScanning] = useState(false);
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const autoDone = useRef<string | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Check-in', href: '/checkin' }];
    const params = { modo: mode, fecha: date, salida: trip ?? undefined };

    const visit = (next: Record<string, unknown>) =>
        router.get('/checkin', { ...params, q: q || undefined, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const search = useCallback(
        (value: string) => {
            const clean = value.trim();
            router.get(
                '/checkin',
                { modo: mode, fecha: date, salida: trip ?? undefined, q: clean || undefined },
                { preserveState: true, replace: true },
            );
        },
        [mode, date, trip],
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        search(term);
    };

    const checkIn = (participant: number, ids: number[], method: string) => {
        setBusy(true);
        router.post(
            '/checkin',
            { modo: mode, participant, activities: ids, method },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setTerm('');
                    input.current?.focus();
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const undo = (c: CheckInInfo) => {
        if (confirm('¿Deshacer este ingreso?')) router.delete(`/checkin/${c.id}`, { preserveScroll: true, preserveState: true });
    };

    // A scanned QR with one paid match and something due today is registered straight away, so the line keeps moving.
    useEffect(() => {
        if (!results || results.method !== 'qr' || results.participants.length !== 1) return;
        const p = results.participants[0];
        const due = p.activities.filter((a) => a.active && a.on_date && !a.check_in).map((a) => a.id);
        const key = `${q}:${p.id}`;
        if (!p.order.paid || due.length === 0 || autoDone.current === key) return;
        autoDone.current = key;
        checkIn(p.id, due, 'qr');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [results, q]);

    const onScan = useCallback(
        (text: string) => {
            setTerm(text);
            search(text);
        },
        [search],
    );

    const expected = activities.reduce((s, a) => s + a.expected, 0);
    const arrived = activities.reduce((s, a) => s + a.checked_in, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Check-in" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title={mode === 'salidas' ? 'Check-in de salidas' : 'Check-in en la entrada'}
                        description={
                            mode === 'salidas'
                                ? 'Registra a cada persona en el punto de encuentro de su salida.'
                                : 'Conferencias, talleres y otras actividades. Busca por documento, nombre o escanea el QR.'
                        }
                    />
                    <div className="flex flex-wrap gap-2">
                        {modes.length > 1 && (
                            <div className="flex rounded-md border p-0.5" role="tablist" aria-label="Punto de check-in">
                                {modes.map((m) => (
                                    <button
                                        key={m}
                                        role="tab"
                                        aria-selected={m === mode}
                                        className={cn(
                                            'rounded px-3 py-1.5 text-sm',
                                            m === mode ? 'bg-primary text-primary-foreground' : 'text-muted-foreground',
                                        )}
                                        onClick={() => router.get('/checkin', { modo: m, fecha: date }, { replace: true })}
                                    >
                                        {MODE_LABELS[m]}
                                    </button>
                                ))}
                            </div>
                        )}
                        <label className="sr-only" htmlFor="checkin-date">
                            Día
                        </label>
                        <select
                            id="checkin-date"
                            className={selectClass}
                            value={date}
                            onChange={(e) => router.get('/checkin', { modo: mode, fecha: e.target.value }, { replace: true })}
                        >
                            {[...new Set([today, ...dates])].sort().map((d) => (
                                <option key={d} value={d}>
                                    {day(d)}
                                    {d === today ? ' (hoy)' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {flash.success && (
                    <Alert role="status" className="border-success/40 bg-success/10">
                        <CircleCheck className="text-success h-5 w-5" aria-hidden />
                        <AlertDescription className="text-base font-medium">{flash.success}</AlertDescription>
                    </Alert>
                )}
                {flash.error && (
                    <Alert variant="destructive" role="alert">
                        <CircleX className="h-5 w-5" aria-hidden />
                        <AlertDescription className="text-base font-medium">{flash.error}</AlertDescription>
                    </Alert>
                )}

                <form onSubmit={submit} className="flex gap-2">
                    <div className="relative flex-1">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2" aria-hidden />
                        <Input
                            ref={input}
                            autoFocus
                            inputMode="search"
                            autoComplete="off"
                            aria-label="Documento, nombre, correo, pedido o código QR"
                            placeholder="Documento, nombre o código"
                            className="h-12 pl-10 text-base"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                        />
                    </div>
                    <Button type="submit" size="lg" className="h-12">
                        Buscar
                    </Button>
                    <Button type="button" size="lg" variant="outline" className="h-12" onClick={() => setScanning(true)}>
                        <ScanLine aria-hidden />
                        <span className="hidden sm:inline">Escanear QR</span>
                    </Button>
                </form>
                <QrScanDialog open={scanning} onOpenChange={setScanning} onScan={onScan} />

                {results && (
                    <section aria-label="Resultados" className="space-y-3">
                        {results.participants.length === 0 && (
                            <div className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                                No encontramos a nadie con «{q}» entre los pedidos pagados o pendientes. Prueba con otro dato o pide el número de
                                pedido.
                            </div>
                        )}
                        {results.participants.map((p) => (
                            <ParticipantCard
                                key={p.id}
                                p={p}
                                method={results.method}
                                busy={busy}
                                canManage={canManage}
                                onCheckIn={checkIn}
                                onUndo={undo}
                            />
                        ))}
                    </section>
                )}

                {mode === 'salidas' ? (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">Salidas del {day(date)}</h2>
                        {activities.length === 0 && <p className="text-muted-foreground text-sm">No hay salidas este día.</p>}
                        <div className="flex flex-wrap gap-2">
                            {activities.map((a) => (
                                <button
                                    key={a.id}
                                    onClick={() => visit({ salida: a.id === trip ? undefined : a.id })}
                                    aria-pressed={a.id === trip}
                                    className={cn(
                                        'rounded-lg border px-3 py-2 text-left text-sm',
                                        a.id === trip ? 'border-primary bg-primary/10' : 'hover:bg-muted/50',
                                    )}
                                >
                                    <span className="block font-medium">{a.name}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {a.start_time ?? ''} · {a.checked_in}/{a.expected} llegaron
                                    </span>
                                </button>
                            ))}
                        </div>
                        {roster && (
                            <Roster rows={roster} busy={busy} onCheckIn={(r) => checkIn(r.participant, [r.activity], 'lista')} onUndo={undo} />
                        )}
                    </section>
                ) : (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">
                            {day(date)}: {number.format(arrived)} de {number.format(expected)} han ingresado
                        </h2>
                        {activities.length === 0 && (
                            <p className="text-muted-foreground text-sm">No hay conferencias, talleres ni otras actividades este día.</p>
                        )}
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {activities.map((a) => {
                                const pct = a.expected ? Math.min(100, Math.round((a.checked_in / a.expected) * 100)) : 0;
                                return (
                                    <li key={a.id} className="rounded-xl border p-3">
                                        <p className="text-sm font-medium">{a.name}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {types[a.type] ?? a.type}
                                            {a.start_time ? ` · ${a.start_time}` : ''}
                                        </p>
                                        <div className="bg-muted mt-2 h-2 overflow-hidden rounded-full" aria-hidden>
                                            <div className="bg-primary h-full" style={{ width: `${pct}%` }} />
                                        </div>
                                        <p className="mt-1 text-xs tabular-nums">
                                            {number.format(a.checked_in)} de {number.format(a.expected)}
                                        </p>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}

                {qr && (
                    <section className="space-y-2 border-t pt-6">
                        <h2 className="text-base font-semibold">Códigos QR de los participantes</h2>
                        <p className="text-muted-foreground text-sm">
                            {number.format(qr.sent)} enviados, {number.format(qr.queued)} en cola y {number.format(qr.pending)} por enviar a quienes
                            tienen actividades próximas. Se envían {qr.per_hour} por hora para no superar el límite del correo.
                        </p>
                        <Button
                            variant="outline"
                            disabled={qr.pending === 0}
                            onClick={() => {
                                if (confirm(`¿Enviar el código QR por correo a ${qr.pending} personas?`)) {
                                    router.post('/checkin/qr-pending', {}, { preserveScroll: true });
                                }
                            }}
                        >
                            <Mail aria-hidden />
                            Enviar QR pendientes
                        </Button>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function ParticipantCard({
    p,
    method,
    busy,
    canManage,
    onCheckIn,
    onUndo,
}: {
    p: Participant;
    method: string;
    busy: boolean;
    canManage: boolean;
    onCheckIn: (participant: number, ids: number[], method: string) => void;
    onUndo: (c: CheckInInfo) => void;
}) {
    const due = p.activities.filter((a) => a.active && !a.check_in);
    const [selected, setSelected] = useState<number[]>(() => due.filter((a) => a.on_date).map((a) => a.id));
    const allIn = p.activities.some((a) => a.check_in) && due.filter((a) => a.on_date).length === 0;

    useEffect(() => {
        setSelected(p.activities.filter((a) => a.active && !a.check_in && a.on_date).map((a) => a.id));
    }, [p]);

    return (
        <article className={cn('space-y-3 rounded-xl border p-4', allIn && 'border-success/50', !p.order.paid && 'border-destructive/50')}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="text-lg font-semibold">{p.name}</h3>
                    <p className="text-muted-foreground text-sm">{[p.document, p.entity, `Pedido #${p.order.number}`].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex flex-wrap gap-1">
                    {!p.order.paid && <Badge variant="destructive">Pedido sin pagar</Badge>}
                    {allIn && <Badge className="bg-success text-white">Ya ingresó</Badge>}
                </div>
            </div>

            {(p.meal_type || p.allergies || p.shirt_size) && (
                <p className="text-sm">
                    {p.meal_type && <>Alimentación: {MEALS[p.meal_type] ?? p.meal_type}. </>}
                    {p.shirt_size && <>Camisa: {p.shirt_size}. </>}
                    {p.allergies && <strong className="text-warning">Alergias: {p.allergies}</strong>}
                </p>
            )}

            {p.activities.length === 0 ? (
                <p className="text-muted-foreground text-sm">No tiene actividades que se registren en este punto.</p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {p.activities.map((a) => (
                        <li key={a.id} className={cn('flex items-center gap-3 px-3 py-2 text-sm', !a.active && 'text-muted-foreground line-through')}>
                            {a.active && !a.check_in && p.order.paid ? (
                                <Checkbox
                                    aria-label={`Registrar ${a.name}`}
                                    checked={selected.includes(a.id)}
                                    onCheckedChange={(v) => setSelected((s) => (v === true ? [...s, a.id] : s.filter((id) => id !== a.id)))}
                                />
                            ) : (
                                <span className="w-4" aria-hidden>
                                    {a.check_in && <CircleCheck className="text-success h-4 w-4" />}
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="font-medium">{a.name}</span>
                                <span className="text-muted-foreground block text-xs">
                                    {day(a.date)}
                                    {a.start_time ? ` · ${a.start_time}` : ''}
                                    {!a.on_date && a.active && !a.check_in ? ' · otro día' : ''}
                                </span>
                            </span>
                            {a.check_in && (
                                <span className="text-success flex items-center gap-2 text-xs">
                                    {arrival(a.check_in)}
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="h-7 w-7"
                                        aria-label="Deshacer ingreso"
                                        onClick={() => onUndo(a.check_in!)}
                                    >
                                        <Undo2 aria-hidden />
                                    </Button>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap gap-2">
                {p.order.paid && due.length > 0 && (
                    <Button size="lg" disabled={busy || selected.length === 0} onClick={() => onCheckIn(p.id, selected, method)}>
                        {busy ? <LoaderCircle className="animate-spin" aria-hidden /> : <CircleCheck aria-hidden />}
                        Registrar ingreso{selected.length > 1 ? ` (${selected.length})` : ''}
                    </Button>
                )}
                <Button variant="ghost" asChild>
                    <a href={`/checkin/qr/${p.id}`} target="_blank" rel="noreferrer">
                        <QrCode aria-hidden />
                        Ver QR
                    </a>
                </Button>
                {canManage && p.order.paid && (
                    <Button
                        variant="ghost"
                        onClick={() => router.post(`/checkin/qr/${p.id}/send`, {}, { preserveScroll: true, preserveState: true })}
                    >
                        <Mail aria-hidden />
                        {p.qr_sent_at ? 'Reenviar QR' : 'Enviar QR'}
                    </Button>
                )}
            </div>
        </article>
    );
}

function Roster({
    rows,
    busy,
    onCheckIn,
    onUndo,
}: {
    rows: RosterRow[];
    busy: boolean;
    onCheckIn: (r: RosterRow) => void;
    onUndo: (c: CheckInInfo) => void;
}) {
    const arrived = rows.filter((r) => r.check_in).length;

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium">
                {arrived} de {rows.length} llegaron
            </p>
            <ul className="divide-y rounded-xl border">
                {rows.length === 0 && <li className="text-muted-foreground p-4 text-sm">Nadie inscrito con pedido pagado.</li>}
                {rows.map((r) => (
                    <li key={r.activity} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{r.name}</p>
                            <p className="text-muted-foreground text-xs">
                                {[r.document, r.whatsapp, r.meal_type ? (MEALS[r.meal_type] ?? r.meal_type) : null].filter(Boolean).join(' · ')}
                            </p>
                            {r.allergies && <p className="text-warning text-xs font-medium">Alergias: {r.allergies}</p>}
                        </div>
                        {r.check_in ? (
                            <span className="text-success flex items-center gap-2 text-xs">
                                {arrival(r.check_in)}
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="h-7 w-7"
                                    aria-label={`Deshacer ingreso de ${r.name}`}
                                    onClick={() => onUndo(r.check_in!)}
                                >
                                    <Undo2 aria-hidden />
                                </Button>
                            </span>
                        ) : (
                            <Button size="sm" disabled={busy} onClick={() => onCheckIn(r)}>
                                Llegó
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
