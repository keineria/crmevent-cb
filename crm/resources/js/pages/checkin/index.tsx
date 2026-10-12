import ParticipantRecordView, { dueToday } from '@/components/checkin/participant-record';
import { type CheckInInfo, type Mode, type Options, type ParticipantRecord, type Permissions } from '@/components/checkin/types';
import Heading from '@/components/heading';
import QrScanDialog from '@/components/qr-scan-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { day, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleCheck, CircleX, Mail, PencilLine, ScanLine, Search, Undo2 } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useRef, useState } from 'react';

interface DayActivity {
    id: number;
    name: string;
    type: string;
    destination: string | null;
    start_time: string | null;
    expected: number;
    checked_in: number;
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
    method: string | null;
    matches: { id: number; name: string; document: string | null; entity: string | null; order: string; paid: boolean }[] | null;
    record: ParticipantRecord | null;
    can: Permissions;
    options: Options;
    types: Record<string, string>;
    canManage: boolean;
    qr: { pending: number; queued: number; sent: number; per_hour: number } | null;
    flash: { success?: string; error?: string };
    [key: string]: unknown;
}

const MODE_LABELS: Record<Mode, string> = { entrada: 'Entrada', salidas: 'Salidas' };
const time = new Intl.DateTimeFormat('es-CO', { hour: 'numeric', minute: '2-digit', timeZone: 'America/Bogota' });
const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

function arrival(c: CheckInInfo) {
    return `Ingresó ${time.format(new Date(c.at))}${c.by ? ` · ${c.by}` : ''}`;
}

export default function CheckInPage() {
    const page = usePage<PageProps>();
    const { mode, modes, date, today, dates, activities, trip, roster, q, method, matches, record, can, options, types, canManage, qr, flash } =
        page.props;
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

    // A scanned QR opens the record and registers what is due today straight away, so the line keeps moving.
    useEffect(() => {
        if (!record || method !== 'qr') return;
        const due = dueToday(record);
        const key = `${q}:${record.id}`;
        if (due.length === 0 || autoDone.current === key) return;
        autoDone.current = key;
        checkIn(record.id, due, 'qr');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [record, method, q]);

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

                {matches && (
                    <section aria-label="Coincidencias" className="space-y-2">
                        {matches.length === 0 ? (
                            <div className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                                No encontramos a nadie con «{q}» entre los pedidos pagados o pendientes. Prueba con otro dato o pide el número de
                                pedido.
                            </div>
                        ) : (
                            <>
                                <p className="text-muted-foreground text-sm">{matches.length} personas coinciden. Elige una:</p>
                                <ul className="divide-y rounded-xl border">
                                    {matches.map((m) => (
                                        <li key={m.id}>
                                            <button
                                                className="hover:bg-muted/50 flex w-full flex-wrap items-center justify-between gap-2 px-4 py-3 text-left"
                                                onClick={() => visit({ persona: m.id, q: undefined })}
                                            >
                                                <span>
                                                    <span className="block font-medium">{m.name}</span>
                                                    <span className="text-muted-foreground text-xs">
                                                        {[m.document, m.entity, `Pedido #${m.order}`].filter(Boolean).join(' · ')}
                                                    </span>
                                                </span>
                                                {!m.paid && <Badge variant="destructive">Sin pagar</Badge>}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </section>
                )}

                {record && (
                    <ParticipantRecordView
                        key={record.id}
                        record={record}
                        method={method ?? 'busqueda'}
                        busy={busy}
                        can={can}
                        canManage={canManage}
                        options={options}
                        onCheckIn={checkIn}
                        onUndo={undo}
                    />
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
                            <Roster
                                rows={roster}
                                meals={options.meals}
                                busy={busy}
                                onCheckIn={(r) => checkIn(r.participant, [r.activity], 'lista')}
                                onUndo={undo}
                            />
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
                        <div className="flex flex-wrap gap-2">
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
                            <Button variant="ghost" asChild>
                                <Link href="/checkin/correo-qr">
                                    <PencilLine aria-hidden />
                                    Editar el correo del QR
                                </Link>
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function Roster({
    rows,
    meals,
    busy,
    onCheckIn,
    onUndo,
}: {
    rows: RosterRow[];
    meals: Record<string, string>;
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
                                {[r.document, r.whatsapp, r.meal_type ? (meals[r.meal_type] ?? r.meal_type) : null].filter(Boolean).join(' · ')}
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
