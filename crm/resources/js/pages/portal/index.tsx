import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { day } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, ListChecks, LoaderCircle, Search } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

interface PortalActivity {
    id: number;
    name: string | null;
    type: string | null;
    date: string | null;
    start_time: string | null;
    checked_in: boolean;
}

interface Person {
    key: string;
    name: string;
    document: string;
    email: string | null;
    entity: string | null;
    shirt_size: string | null;
    meal_type: string | null;
    qr_sent: boolean;
    orders: string[];
    activities: PortalActivity[];
    days: string[];
}

type Challenge = { type: 'turnstile'; site_key: string } | { type: 'sum'; question: string };

interface Props {
    people: Person[];
    challenge: Challenge | null;
    helpEmail: string | null;
}

interface Flash {
    [key: string]: unknown;
    flash: { success?: string | null; error?: string | null };
}

declare global {
    interface Window {
        turnstile?: {
            render: (
                el: HTMLElement,
                options: { sitekey: string; language?: string; callback: (token: string) => void; 'expired-callback'?: () => void },
            ) => string;
            remove: (id: string) => void;
        };
    }
}

const TYPE_LABELS: Record<string, string> = { conference: 'Conferencia', birding: 'Salida de pajareo', workshop: 'Taller', other: 'Otra actividad' };
const MEAL_LABELS: Record<string, string> = { traditional: 'Tradicional', vegetarian: 'Vegetariana', vegan: 'Vegana' };
const TYPE_DOT: Record<string, string> = { conference: 'bg-primary', birding: 'bg-cta', workshop: 'bg-success', other: 'bg-muted-foreground' };

function utcDate(value: string): Date {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
}

const weekday = new Intl.DateTimeFormat('es-CO', { weekday: 'short', timeZone: 'UTC' });
const longDay = new Intl.DateTimeFormat('es-CO', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(new Date());

export default function Portal({ people, challenge, helpEmail }: Props) {
    const { flash } = usePage<Flash>().props;

    return (
        <div className="bg-background min-h-svh">
            <Head title="Inscritos" />
            <header className="border-b">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <AppLogoIcon className="text-primary size-7" />
                        <div className="leading-tight">
                            <p className="font-semibold">Colombia Birdfair</p>
                            <p className="text-muted-foreground text-xs">Inscritos</p>
                        </div>
                    </div>
                    {people.length > 0 && (
                        <Button variant="ghost" size="sm" onClick={() => router.post('/inscritos/salir')}>
                            <Search className="size-4" /> Otra consulta
                        </Button>
                    )}
                </div>
            </header>

            <main className="mx-auto max-w-3xl space-y-4 px-4 py-6">
                {flash.error && (
                    <Alert variant="destructive">
                        <AlertDescription>{flash.error}</AlertDescription>
                    </Alert>
                )}

                {people.length > 0 ? <Registered people={people} helpEmail={helpEmail} /> : challenge && <Lookup challenge={challenge} />}
            </main>
        </div>
    );
}

function Lookup({ challenge }: { challenge: Challenge }) {
    const { data, setData, post, processing, errors } = useForm({ document: '', human: '', website: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/inscritos/consultar', { preserveScroll: true, onError: () => setData('human', '') });
    };

    return (
        <section className="mx-auto max-w-sm space-y-6 pt-6">
            <div className="space-y-2 text-center">
                <h1 className="text-2xl font-semibold">Consulta tu inscripción</h1>
                <p className="text-muted-foreground text-sm">Escribe tu número de documento para ver tus actividades y tu calendario en la feria.</p>
            </div>
            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="document">Número de documento</Label>
                    <Input
                        id="document"
                        inputMode="text"
                        autoComplete="off"
                        required
                        value={data.document}
                        onChange={(e) => setData('document', e.target.value)}
                        placeholder="Sin puntos ni espacios"
                    />
                    <InputError message={errors.document} />
                </div>

                {/* Honeypot: hidden from people, filled by bots. */}
                <input
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    className="hidden"
                    aria-hidden="true"
                    value={data.website}
                    onChange={(e) => setData('website', e.target.value)}
                />

                {challenge.type === 'turnstile' ? (
                    <Turnstile siteKey={challenge.site_key} onToken={(t) => setData('human', t)} />
                ) : (
                    <div className="space-y-2">
                        <Label htmlFor="human">{challenge.question}</Label>
                        <Input
                            id="human"
                            inputMode="numeric"
                            autoComplete="off"
                            required
                            value={data.human}
                            onChange={(e) => setData('human', e.target.value)}
                        />
                    </div>
                )}
                <InputError message={errors.human} />

                <Button type="submit" className="w-full" disabled={processing || !data.human}>
                    {processing ? <LoaderCircle className="size-4 animate-spin" /> : <Search className="size-4" />}
                    Consultar
                </Button>
            </form>
        </section>
    );
}

function Turnstile({ siteKey, onToken }: { siteKey: string; onToken: (token: string) => void }) {
    const box = useRef<HTMLDivElement>(null);
    const callback = useRef(onToken);
    callback.current = onToken;

    useEffect(() => {
        let id: string | undefined;
        const mount = () => {
            if (box.current && window.turnstile && !id) {
                id = window.turnstile.render(box.current, {
                    sitekey: siteKey,
                    language: 'es',
                    callback: (t) => callback.current(t),
                    'expired-callback': () => callback.current(''),
                });
            }
        };

        let script = document.querySelector<HTMLScriptElement>('script[data-turnstile]');
        if (!script) {
            script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.dataset.turnstile = '1';
            document.head.appendChild(script);
        }
        script.addEventListener('load', mount);
        mount();

        return () => {
            script?.removeEventListener('load', mount);
            if (id) window.turnstile?.remove(id);
        };
    }, [siteKey]);

    return <div ref={box} className="flex justify-center" />;
}

function Registered({ people, helpEmail }: { people: Person[]; helpEmail: string | null }) {
    const [key, setKey] = useState(people[0].key);
    const person = people.find((p) => p.key === key) ?? people[0];

    return (
        <div className="space-y-4">
            {people.length > 1 && (
                <div className="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Personas con este documento">
                    {people.map((p) => (
                        <Button
                            key={p.key}
                            role="tab"
                            aria-selected={p.key === person.key}
                            variant={p.key === person.key ? 'default' : 'outline'}
                            size="sm"
                            className="shrink-0"
                            onClick={() => setKey(p.key)}
                        >
                            {p.name}
                        </Button>
                    ))}
                </div>
            )}

            <Tabs defaultValue="inscripcion">
                <TabsList className="grid w-full grid-cols-2">
                    <TabsTrigger value="inscripcion">
                        <ListChecks className="mr-1.5 size-4" /> Mi inscripción
                    </TabsTrigger>
                    <TabsTrigger value="calendario">
                        <CalendarDays className="mr-1.5 size-4" /> Calendario
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="inscripcion" className="mt-4">
                    <Registration person={person} helpEmail={helpEmail} />
                </TabsContent>
                <TabsContent value="calendario" className="mt-4">
                    <Calendar key={person.key} person={person} />
                </TabsContent>
            </Tabs>
        </div>
    );
}

function Registration({ person, helpEmail }: { person: Person; helpEmail: string | null }) {
    const details: [string, string | null][] = [
        ['Documento', person.document],
        ['Entidad', person.entity],
        ['Camiseta', person.shirt_size],
        ['Alimentación', person.meal_type ? (MEAL_LABELS[person.meal_type] ?? person.meal_type) : null],
        [person.orders.length === 1 ? 'Pedido' : 'Pedidos', person.orders.map((o) => `#${o}`).join(', ')],
    ];

    return (
        <div className="space-y-4">
            <section className="rounded-xl border px-4 py-4">
                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Inscripción pagada</p>
                <h1 className="text-xl font-semibold">{person.name}</h1>
                <dl className="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    {details
                        .filter(([, v]) => v)
                        .map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-muted-foreground text-xs">{label}</dt>
                                <dd className="break-words">{value}</dd>
                            </div>
                        ))}
                </dl>
                <p className="text-muted-foreground mt-3 text-sm">
                    {person.qr_sent && person.email
                        ? `Tu código QR de ingreso se envió a ${person.email}.`
                        : person.email
                          ? `Tu código QR de ingreso llegará a ${person.email} antes de la feria.`
                          : 'En la entrada también puedes dar tu número de documento.'}
                </p>
            </section>

            <section className="rounded-xl border">
                <h2 className="border-b px-4 py-3 font-semibold">Mis actividades</h2>
                {person.activities.length === 0 ? (
                    <p className="text-muted-foreground px-4 py-6 text-sm">No tienes actividades activas.</p>
                ) : (
                    <ul className="divide-y">
                        {person.activities.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="font-medium">{a.name}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {[TYPE_LABELS[a.type ?? ''] ?? a.type, a.date ? day(a.date) : null, a.start_time].filter(Boolean).join(' · ')}
                                    </p>
                                </div>
                                {a.checked_in && (
                                    <Badge className="bg-success/15 text-success border-success/30" variant="outline">
                                        Ingresaste
                                    </Badge>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-muted-foreground border-t px-4 py-3 text-xs">
                    ¿Ves algo incorrecto?{' '}
                    {helpEmail ? (
                        <>
                            Escríbenos a{' '}
                            <a className="text-primary underline" href={`mailto:${helpEmail}`}>
                                {helpEmail}
                            </a>
                            .
                        </>
                    ) : (
                        'Comunícate con el equipo de registro.'
                    )}
                </p>
            </section>
        </div>
    );
}

function Calendar({ person }: { person: Person }) {
    const days = person.days;
    const [selected, setSelected] = useState(() => (days.includes(today) ? today : (days[0] ?? null)));
    const items = useMemo(() => person.activities.filter((a) => a.date === selected), [person, selected]);

    if (!days.length || !selected) {
        return <p className="text-muted-foreground rounded-xl border px-4 py-6 text-sm">Aún no tienes actividades con fecha.</p>;
    }

    return (
        <section className="space-y-4">
            <div className="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Días de la feria">
                {days.map((d) => {
                    const has = person.activities.some((a) => a.date === d);
                    const active = d === selected;
                    return (
                        <button
                            key={d}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            aria-label={longDay.format(utcDate(d))}
                            onClick={() => setSelected(d)}
                            className={cn(
                                'flex min-w-14 shrink-0 flex-col items-center rounded-xl border px-3 py-2 transition-colors',
                                active ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted/50',
                                !has && !active && 'text-muted-foreground',
                            )}
                        >
                            <span className="text-[11px] font-medium uppercase">{weekday.format(utcDate(d)).replace('.', '')}</span>
                            <span className="text-lg leading-tight font-bold">{utcDate(d).getUTCDate()}</span>
                            <span
                                className={cn(
                                    'mt-0.5 size-1.5 rounded-full',
                                    has ? (active ? 'bg-primary-foreground' : 'bg-primary') : 'bg-transparent',
                                )}
                            />
                        </button>
                    );
                })}
            </div>

            <h2 className="font-semibold first-letter:uppercase">{longDay.format(utcDate(selected))}</h2>

            {items.length === 0 ? (
                <p className="text-muted-foreground rounded-xl border px-4 py-6 text-sm">No tienes actividades este día.</p>
            ) : (
                <ol className="space-y-2">
                    {items.map((a) => (
                        <li key={a.id} className="flex items-stretch gap-3 rounded-xl border p-3">
                            <p className="w-16 shrink-0 text-center text-sm font-semibold tabular-nums">{a.start_time ?? 'Todo el día'}</p>
                            <div className="bg-border w-px" />
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{a.name}</p>
                                <p className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                    <span className={cn('size-2 rounded-full', TYPE_DOT[a.type ?? ''] ?? 'bg-muted-foreground')} />
                                    {TYPE_LABELS[a.type ?? ''] ?? a.type}
                                    {a.checked_in ? ' · Ingresaste' : ''}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
