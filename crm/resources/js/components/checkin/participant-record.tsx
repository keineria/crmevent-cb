import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ACTIVITY_STATUS, day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { ArrowLeftRight, CircleCheck, LoaderCircle, Mail, Pencil, Plus, QrCode, Trash2, Undo2 } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';
import { crmJson, type CheckInInfo, type DeskActivity, type Options, type ParticipantRecord, type Permissions, type RecordActivity } from './types';

const time = new Intl.DateTimeFormat('es-CO', { hour: 'numeric', minute: '2-digit', timeZone: 'America/Bogota' });
const orderDate = new Intl.DateTimeFormat('es-CO', { dateStyle: 'long', timeZone: 'America/Bogota' });
const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

const TYPE_LABELS: Record<string, string> = { conference: 'Conferencia', birding: 'Salida de pajareo', workshop: 'Taller', other: 'Otra actividad' };

function arrival(c: CheckInInfo) {
    return `Ingresó ${time.format(new Date(c.at))}${c.by ? ` · ${c.by}` : ''}`;
}

/** Activities that can be checked in right now at this desk. */
export function dueToday(record: ParticipantRecord): number[] {
    return record.orders.flatMap((o) => (o.paid ? o.activities.filter((a) => a.here && a.active && a.on_date && !a.check_in).map((a) => a.id) : []));
}

interface Props {
    record: ParticipantRecord;
    method: string;
    busy: boolean;
    can: Permissions;
    canManage: boolean;
    options: Options;
    onCheckIn: (participant: number, ids: number[], method: string) => void;
    onUndo: (c: CheckInInfo) => void;
}

export default function ParticipantRecordView({ record, method, busy, can, canManage, options, onCheckIn, onUndo }: Props) {
    const checkable = useMemo(
        () => record.orders.flatMap((o) => (o.paid ? o.activities.filter((a) => a.here && a.active && !a.check_in) : [])),
        [record],
    );
    const [selected, setSelected] = useState<number[]>(() => dueToday(record));
    const [editing, setEditing] = useState(false);
    const [replacing, setReplacing] = useState<RecordActivity | null>(null);
    const [adding, setAdding] = useState(false);

    useEffect(() => setSelected(dueToday(record)), [record]);

    const hereToday = record.orders.flatMap((o) => o.activities.filter((a) => a.here && a.active && a.on_date));
    const allInToday = hereToday.length > 0 && hereToday.every((a) => a.check_in);
    const unpaid = record.orders.some((o) => !o.paid);
    const allergies = record.allergies && !/^(no|ninguna?|n\/a|-)$/i.test(record.allergies.trim()) ? record.allergies : null;

    const remove = (a: RecordActivity) => {
        if (confirm(`¿Retirar «${a.name}»? Se libera su cupo y el pago no cambia.`)) {
            router.post(`/checkin/actividad/${a.id}/retirar`, {}, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <article className="overflow-hidden rounded-xl border" aria-label={`Ficha de ${record.name}`}>
            <header className={cn('border-l-4 px-4 py-3', allInToday ? 'border-l-success bg-success/5' : 'border-l-primary bg-muted/30')}>
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">Ficha del inscrito</p>
                        <h2 className="text-xl leading-tight font-bold">{record.name}</h2>
                        <p className="text-muted-foreground text-sm">
                            {[record.document_label, record.document_number].filter(Boolean).join(' · ')}
                            {record.entity ? ` · ${record.entity}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge variant="secondary">
                            {record.totals.confirmed} {record.totals.confirmed === 1 ? 'actividad' : 'actividades'}
                        </Badge>
                        {allInToday && <Badge className="bg-success text-white">Ya ingresó hoy</Badge>}
                        {unpaid && <Badge variant="destructive">Pedido sin pagar</Badge>}
                    </div>
                </div>
                <div className="mt-2 flex flex-wrap gap-1">
                    {can.edit_details && record.linked && (
                        <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                            <Pencil aria-hidden />
                            Editar datos
                        </Button>
                    )}
                    {can.edit_activities && record.linked && (
                        <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
                            <Plus aria-hidden />
                            Agregar actividades
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" asChild>
                        <a href={`/checkin/qr/${record.id}`} target="_blank" rel="noreferrer">
                            <QrCode aria-hidden />
                            QR
                        </a>
                    </Button>
                    {canManage && !unpaid && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => router.post(`/checkin/qr/${record.id}/send`, {}, { preserveScroll: true, preserveState: true })}
                        >
                            <Mail aria-hidden />
                            {record.qr_sent_at ? 'Reenviar QR' : 'Enviar QR'}
                        </Button>
                    )}
                </div>
            </header>

            <dl className="grid grid-cols-2 border-t text-sm sm:grid-cols-3 lg:grid-cols-5 [&>div]:border-b [&>div]:px-4 [&>div]:py-2 sm:[&>div]:border-r">
                <Field label="Correo" value={record.email} className="col-span-2 sm:col-span-1" />
                <Field
                    label="WhatsApp"
                    value={
                        record.whatsapp ? (
                            <a
                                className="text-primary underline-offset-2 hover:underline"
                                href={`https://wa.me/${record.whatsapp.replace(/\D/g, '')}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {record.whatsapp}
                            </a>
                        ) : null
                    }
                />
                <Field label="Camiseta" value={record.shirt_size} />
                <Field label="Alimentación" value={record.meal_type ? (options.meals[record.meal_type] ?? record.meal_type) : null} />
                <Field
                    label="Alergias"
                    value={allergies ? <strong className="text-warning">{allergies}</strong> : (record.allergies ?? null)}
                    className={cn(allergies && 'bg-warning/10')}
                />
            </dl>

            <div className="grid grid-cols-3 gap-2 px-4 py-3">
                <Money label="Valor actividades" value={money.format(record.totals.price)} />
                <Money
                    label="Descuentos"
                    value={record.totals.discount ? `− ${money.format(record.totals.discount)}` : money.format(0)}
                    tone="text-warning"
                />
                <Money label="Neto pagado" value={money.format(record.totals.total)} strong />
            </div>
            {record.discounts.length > 0 && (
                <div className="flex flex-wrap gap-2 px-4 pb-3">
                    {record.discounts.map((d) => (
                        <span key={d.label} className="bg-warning/10 text-warning rounded-full px-2.5 py-1 text-xs font-medium">
                            {d.label} · − {money.format(d.amount)}
                        </span>
                    ))}
                </div>
            )}

            {checkable.length > 0 && (
                <div className="bg-primary/5 sticky bottom-0 z-10 flex flex-wrap items-center gap-3 border-y px-4 py-3">
                    <Button size="lg" disabled={busy || selected.length === 0} onClick={() => onCheckIn(record.id, selected, method)}>
                        {busy ? <LoaderCircle className="animate-spin" aria-hidden /> : <CircleCheck aria-hidden />}
                        Registrar ingreso{selected.length > 1 ? ` (${selected.length})` : ''}
                    </Button>
                    <p className="text-muted-foreground text-xs">
                        {selected.length === 0
                            ? 'Marca las actividades a las que entra.'
                            : 'Quedan marcadas las de este día; puedes cambiar la selección.'}
                    </p>
                </div>
            )}

            <div className="divide-y">
                {record.orders.map((o) => (
                    <section key={o.number} aria-label={`Pedido ${o.number}`}>
                        <div className="bg-muted/30 flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-xs">
                            <span>
                                <strong className="text-sm">Pedido #{o.number}</strong>
                                {o.ordered_at && <span className="text-muted-foreground"> · {orderDate.format(new Date(o.ordered_at))}</span>}
                                {o.registered_by && <span className="text-muted-foreground"> · registró {o.registered_by}</span>}
                            </span>
                            <Badge variant={o.paid ? 'secondary' : 'destructive'}>{o.paid ? 'Pagado' : 'Sin pagar'}</Badge>
                        </div>
                        <ul className="divide-y">
                            {o.activities.map((a) => {
                                const canCheck = o.paid && a.here && a.active && !a.check_in;
                                return (
                                    <li
                                        key={a.id}
                                        className={cn('flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5', !a.active && 'opacity-60')}
                                    >
                                        <span className="flex w-5 justify-center">
                                            {canCheck ? (
                                                <Checkbox
                                                    aria-label={`Registrar ingreso a ${a.name}`}
                                                    checked={selected.includes(a.id)}
                                                    onCheckedChange={(v) =>
                                                        setSelected((s) => (v === true ? [...s, a.id] : s.filter((id) => id !== a.id)))
                                                    }
                                                />
                                            ) : a.check_in ? (
                                                <CircleCheck className="text-success h-4 w-4" aria-label="Ya ingresó" />
                                            ) : null}
                                        </span>
                                        <div className="min-w-0 flex-1 basis-56">
                                            <p className={cn('text-sm font-semibold', !a.active && 'line-through')}>{a.name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {TYPE_LABELS[a.type ?? ''] ?? a.type} · {day(a.date)}
                                                {a.start_time ? ` · ${a.start_time}` : ''}
                                                {a.active && a.here && !a.on_date && !a.check_in ? ' · otro día' : ''}
                                            </p>
                                            {a.discount_label && <p className="text-warning text-xs">{a.discount_label}</p>}
                                            {a.check_in && (
                                                <p className="text-success flex items-center gap-1 text-xs">
                                                    {arrival(a.check_in)}
                                                    <button
                                                        className="hover:text-foreground rounded p-0.5"
                                                        aria-label="Deshacer ingreso"
                                                        onClick={() => onUndo(a.check_in!)}
                                                    >
                                                        <Undo2 className="h-3.5 w-3.5" aria-hidden />
                                                    </button>
                                                </p>
                                            )}
                                        </div>
                                        <Badge variant={a.active ? 'secondary' : 'outline'} className="shrink-0">
                                            {ACTIVITY_STATUS[a.status] ?? a.status}
                                        </Badge>
                                        <span className="w-28 shrink-0 text-right text-xs tabular-nums">
                                            <span className="block text-sm font-semibold">{money.format(a.total)}</span>
                                            {a.discount > 0 && <span className="text-muted-foreground line-through">{money.format(a.price)}</span>}
                                        </span>
                                        {can.edit_activities && a.active && !a.check_in && record.linked && (
                                            <span className="flex shrink-0 gap-0.5">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="h-8 w-8"
                                                    aria-label={`Cambiar ${a.name}`}
                                                    onClick={() => setReplacing(a)}
                                                >
                                                    <ArrowLeftRight aria-hidden />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="h-8 w-8"
                                                    aria-label={`Retirar ${a.name}`}
                                                    onClick={() => remove(a)}
                                                >
                                                    <Trash2 aria-hidden />
                                                </Button>
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ))}
            </div>

            {editing && <EditDetailsDialog record={record} options={options} onClose={() => setEditing(false)} />}
            {replacing && <ReplaceDialog activity={replacing} onClose={() => setReplacing(null)} />}
            {adding && <AddActivitiesDialog record={record} onClose={() => setAdding(false)} />}
        </article>
    );
}

function Field({ label, value, className }: { label: string; value: React.ReactNode; className?: string }) {
    return (
        <div className={className}>
            <dt className="text-muted-foreground text-[11px] uppercase">{label}</dt>
            <dd className="truncate">{value || '—'}</dd>
        </div>
    );
}

function Money({ label, value, tone, strong }: { label: string; value: string; tone?: string; strong?: boolean }) {
    return (
        <div className="rounded-lg border px-3 py-2">
            <p className="text-muted-foreground text-[11px]">{label}</p>
            <p className={cn('text-sm whitespace-nowrap tabular-nums sm:text-base', strong && 'font-bold', tone)}>{value}</p>
        </div>
    );
}

function EditDetailsDialog({ record, options, onClose }: { record: ParticipantRecord; options: Options; onClose: () => void }) {
    const { data, setData, put, processing, errors } = useForm({
        full_name: record.name,
        email: record.email ?? '',
        whatsapp: record.whatsapp ?? '',
        shirt_size: record.shirt_size ?? '',
        meal_type: record.meal_type ?? '',
        allergies: record.allergies ?? '',
        entity: record.entity ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/checkin/persona/${record.id}/datos`, { preserveScroll: true, preserveState: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Editar datos</DialogTitle>
                    <DialogDescription>Se guardan en el plugin de inscripciones. El documento no se cambia aquí.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5 sm:col-span-2">
                        <Label htmlFor="ed-name">Nombre completo</Label>
                        <Input id="ed-name" required value={data.full_name} onChange={(e) => setData('full_name', e.target.value)} />
                        <InputError message={errors.full_name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="ed-email">Correo</Label>
                        <Input id="ed-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="ed-wa">WhatsApp</Label>
                        <Input
                            id="ed-wa"
                            inputMode="tel"
                            placeholder="+573001234567"
                            value={data.whatsapp}
                            onChange={(e) => setData('whatsapp', e.target.value)}
                        />
                        <InputError message={errors.whatsapp} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="ed-shirt">Camiseta</Label>
                        <select id="ed-shirt" className={selectClass} value={data.shirt_size} onChange={(e) => setData('shirt_size', e.target.value)}>
                            <option value="">Sin dato</option>
                            {options.shirts.map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="ed-meal">Alimentación</Label>
                        <select id="ed-meal" className={selectClass} value={data.meal_type} onChange={(e) => setData('meal_type', e.target.value)}>
                            <option value="">Sin dato</option>
                            {Object.entries(options.meals).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5 sm:col-span-2">
                        <Label htmlFor="ed-entity">Entidad</Label>
                        <Input id="ed-entity" value={data.entity} onChange={(e) => setData('entity', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5 sm:col-span-2">
                        <Label htmlFor="ed-allergies">Alergias</Label>
                        <Textarea id="ed-allergies" rows={2} value={data.allergies} onChange={(e) => setData('allergies', e.target.value)} />
                        <InputError message={errors.allergies} />
                    </div>
                    <DialogFooter className="sm:col-span-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function useDeskActivities() {
    const [list, setList] = useState<DeskActivity[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        crmJson<{ data: DeskActivity[] }>('/checkin/actividades-disponibles')
            .then((r) => setList(r.data))
            .catch((e: Error) => setError(e.message));
    }, []);
    return { list, error };
}

function activityLabel(a: DeskActivity) {
    return `${a.name} · ${day(a.date)}${a.start_time ? ` ${a.start_time.slice(0, 5)}` : ''} · ${money.format(Number(a.price))} · ${a.available} libres`;
}

function ReplaceDialog({ activity, onClose }: { activity: RecordActivity; onClose: () => void }) {
    const { list, error } = useDeskActivities();
    const [target, setTarget] = useState('');
    const [busy, setBusy] = useState(false);
    const options = (list ?? []).filter((a) => a.name !== activity.name && (!activity.type || a.type === activity.type));

    const submit = () => {
        setBusy(true);
        router.post(
            `/checkin/actividad/${activity.id}/cambiar`,
            { activity_id: Number(target) },
            { preserveScroll: true, preserveState: true, onSuccess: onClose, onFinish: () => setBusy(false) },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Cambiar «{activity.name}»</DialogTitle>
                    <DialogDescription>
                        Ocupa un cupo en la nueva actividad y libera el actual. El pago no cambia, aunque la nueva cueste menos.
                    </DialogDescription>
                </DialogHeader>
                {error && <p className="text-destructive text-sm">{error}</p>}
                {!list && !error && <LoaderCircle className="text-muted-foreground mx-auto animate-spin" aria-label="Cargando" />}
                {list && (
                    <>
                        <Label htmlFor="replace-target">Nueva actividad</Label>
                        <select id="replace-target" className={selectClass} value={target} onChange={(e) => setTarget(e.target.value)}>
                            <option value="">Elige una actividad con cupo</option>
                            {options.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {activityLabel(a)}
                                </option>
                            ))}
                        </select>
                    </>
                )}
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button disabled={!target || busy} onClick={submit}>
                        {busy && <LoaderCircle className="animate-spin" aria-hidden />}
                        Cambiar actividad
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

interface Preview {
    activities: { id: number; name: string; price: string; discount: string; total: string }[];
    subtotal: string;
    discount: string;
    total: string;
    free: boolean;
}

function AddActivitiesDialog({ record, onClose }: { record: ParticipantRecord; onClose: () => void }) {
    const { list, error } = useDeskActivities();
    const owned = new Set(record.orders.flatMap((o) => o.activities.filter((a) => a.active).map((a) => a.name)));
    const [ids, setIds] = useState<number[]>([]);
    const [entity, setEntity] = useState(record.entity ?? '');
    const [free, setFree] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    // Any change in the selection invalidates the reviewed total.
    useEffect(() => setPreview(null), [ids, free, entity]);

    const body = { activity_ids: ids, entity, free };

    const review = () => {
        setBusy(true);
        setProblem(null);
        crmJson<Preview>(`/checkin/persona/${record.id}/agregar/revisar`, body)
            .then(setPreview)
            .catch((e: Error) => setProblem(e.message))
            .finally(() => setBusy(false));
    };

    const confirmAdd = () => {
        if (!preview) return;
        setBusy(true);
        router.post(
            `/checkin/persona/${record.id}/agregar`,
            { ...body, expected_total: Number(preview.total) },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: onClose,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Agregar actividades</DialogTitle>
                    <DialogDescription>Crea un pedido nuevo para {record.name}, igual que "Nuevo Registro" en el plugin.</DialogDescription>
                </DialogHeader>
                {error && <p className="text-destructive text-sm">{error}</p>}
                {!list && !error && <LoaderCircle className="text-muted-foreground mx-auto animate-spin" aria-label="Cargando" />}
                {list && (
                    <fieldset className="max-h-64 space-y-1 overflow-y-auto rounded-lg border p-2">
                        <legend className="sr-only">Actividades con cupo</legend>
                        {list
                            .filter((a) => !owned.has(a.name))
                            .map((a) => (
                                <label key={a.id} className="hover:bg-muted/50 flex items-start gap-3 rounded p-1.5 text-sm">
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={ids.includes(a.id)}
                                        onCheckedChange={(v) => setIds((s) => (v === true ? [...s, a.id] : s.filter((id) => id !== a.id)))}
                                    />
                                    <span>{activityLabel(a)}</span>
                                </label>
                            ))}
                    </fieldset>
                )}
                <div className="grid gap-1.5">
                    <Label htmlFor="add-entity">Entidad</Label>
                    <Input id="add-entity" required value={entity} onChange={(e) => setEntity(e.target.value)} />
                </div>
                <label className="flex items-center gap-3 text-sm">
                    <Checkbox checked={free} onCheckedChange={(v) => setFree(v === true)} />
                    Inscribir sin costo (usa el código reservado de la mesa)
                </label>

                {problem && <p className="text-destructive text-sm">{problem}</p>}

                {preview && (
                    <div className="space-y-1 rounded-lg border p-3 text-sm">
                        {preview.activities.map((a) => (
                            <p key={a.id} className="flex justify-between gap-2">
                                <span>{a.name}</span>
                                <span className="tabular-nums">{money.format(Number(a.total))}</span>
                            </p>
                        ))}
                        {Number(preview.discount) > 0 && (
                            <p className="text-warning flex justify-between">
                                <span>Descuento</span>
                                <span>− {money.format(Number(preview.discount))}</span>
                            </p>
                        )}
                        <p className="flex justify-between border-t pt-1 font-bold">
                            <span>Valor a cobrar</span>
                            <span>{money.format(Number(preview.total))}</span>
                        </p>
                    </div>
                )}

                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                    {preview ? (
                        <Button disabled={busy} onClick={confirmAdd}>
                            {busy && <LoaderCircle className="animate-spin" aria-hidden />}
                            {preview.free || Number(preview.total) === 0
                                ? 'Confirmar registro'
                                : `Confirmar: cobré ${money.format(Number(preview.total))}`}
                        </Button>
                    ) : (
                        <Button disabled={busy || ids.length === 0 || !entity.trim()} onClick={review}>
                            {busy && <LoaderCircle className="animate-spin" aria-hidden />}
                            Revisar valor
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
