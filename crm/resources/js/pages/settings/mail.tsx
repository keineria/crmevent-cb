import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleDashed, CircleX, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Profile = 'transactional' | 'broadcast';
type Provider = 'smtp' | 'ses' | 'brevo' | 'resend';

interface MailSetting {
    profile: Profile;
    provider: Provider;
    host: string | null;
    port: number | null;
    encryption: 'tls' | 'ssl';
    aws_region: string | null;
    username: string | null;
    password_hint: string | null;
    from_name: string | null;
    from_address: string | null;
    reply_to: string | null;
    dkim_selector: string | null;
    hourly_limit: number | null;
    daily_limit: number | null;
    track_opens: boolean;
    track_clicks: boolean;
    footer_text: string | null;
    is_active: boolean;
    pending_test: boolean;
    verified_at: string | null;
    active_from: string | null;
    active_host: string | null;
    last_test_error: string | null;
}

interface DnsResult {
    domain: string;
    spf: boolean;
    dkim: boolean | null;
    dmarc: boolean;
}

interface PageProps extends SharedData {
    settings: Record<Profile, MailSetting>;
    webhookUrl: string;
    flash: { success?: string | null; error?: string | null; dns?: Partial<Record<Profile, DnsResult>> | null };
}

const PROFILES: { key: Profile; label: string; description: string }[] = [
    { key: 'transactional', label: 'Transaccional', description: 'Acceso a la inscripción, recomendaciones y reenvíos.' },
    { key: 'broadcast', label: 'Difusión', description: 'Campañas y correos masivos. Exige pie legal con enlace de baja.' },
];

const PROVIDERS: { value: Provider; label: string }[] = [
    { value: 'smtp', label: 'SMTP personalizado' },
    { value: 'ses', label: 'Amazon SES (SMTP)' },
    { value: 'brevo', label: 'Brevo (SMTP)' },
    { value: 'resend', label: 'Resend (SMTP)' },
];

/** Connection defaults each provider documents for SMTP relay. */
function presetFor(provider: Provider, region: string) {
    switch (provider) {
        case 'ses':
            return { host: `email-smtp.${region || 'us-east-1'}.amazonaws.com`, port: 587, encryption: 'tls' as const };
        case 'brevo':
            return { host: 'smtp-relay.brevo.com', port: 587, encryption: 'tls' as const };
        case 'resend':
            return { host: 'smtp.resend.com', port: 465, encryption: 'ssl' as const, username: 'resend' };
        default:
            return null;
    }
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Correo de envíos', href: '/settings/mail' }];

const selectClass =
    'border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

export default function MailSettings() {
    const { settings, webhookUrl, flash } = usePage<PageProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Correo de envíos" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Correo de envíos"
                        description="Conecta el servicio que envía los correos del CRM. Una configuración nueva solo se activa cuando pasa el correo de prueba."
                    />

                    {flash.success && (
                        <Alert role="status">
                            <CircleCheck className="h-4 w-4" aria-hidden />
                            <AlertDescription>{flash.success}</AlertDescription>
                        </Alert>
                    )}
                    {flash.error && (
                        <Alert variant="destructive">
                            <CircleX className="h-4 w-4" aria-hidden />
                            <AlertDescription>{flash.error}</AlertDescription>
                        </Alert>
                    )}

                    <Tabs defaultValue="transactional">
                        <TabsList aria-label="Perfil de envío">
                            {PROFILES.map((p) => (
                                <TabsTrigger key={p.key} value={p.key}>
                                    {p.label}
                                    <StatusDot setting={settings[p.key]} />
                                </TabsTrigger>
                            ))}
                        </TabsList>

                        {PROFILES.map((p) => (
                            <TabsContent key={p.key} value={p.key}>
                                <ProfileForm
                                    setting={settings[p.key]}
                                    description={p.description}
                                    webhookUrl={webhookUrl}
                                    dns={flash.dns?.[p.key] ?? null}
                                />
                            </TabsContent>
                        ))}
                    </Tabs>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function StatusDot({ setting }: { setting: MailSetting }) {
    const label = setting.is_active ? 'activo' : 'sin activar';

    return <span className={`h-2 w-2 rounded-full ${setting.is_active ? 'bg-success' : 'bg-warning'}`} role="img" aria-label={label} />;
}

function StatusChip({ setting }: { setting: MailSetting }) {
    if (setting.is_active && !setting.pending_test) {
        return (
            <p className="text-success flex items-center gap-2 text-sm font-medium">
                <CircleCheck className="h-4 w-4" aria-hidden />
                Activa desde {formatDate(setting.verified_at)} · envía como {setting.active_from}
            </p>
        );
    }

    if (setting.is_active && setting.pending_test) {
        return (
            <p className="text-warning flex items-center gap-2 text-sm font-medium">
                <CircleAlert className="h-4 w-4" aria-hidden />
                Cambios sin probar. Se sigue enviando con {setting.active_host} como {setting.active_from}.
            </p>
        );
    }

    return (
        <p className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
            <CircleDashed className="h-4 w-4" aria-hidden />
            Sin activar: guarda los datos y envía un correo de prueba.
        </p>
    );
}

function ProfileForm({
    setting,
    description,
    webhookUrl,
    dns,
}: {
    setting: MailSetting;
    description: string;
    webhookUrl: string;
    dns: DnsResult | null;
}) {
    const profile = setting.profile;
    const { data, setData, put, processing, errors } = useForm({
        provider: setting.provider,
        host: setting.host ?? '',
        port: setting.port ?? 587,
        encryption: setting.encryption,
        aws_region: setting.aws_region ?? '',
        username: setting.username ?? '',
        password: '',
        from_name: setting.from_name ?? '',
        from_address: setting.from_address ?? '',
        reply_to: setting.reply_to ?? '',
        dkim_selector: setting.dkim_selector ?? '',
        hourly_limit: setting.hourly_limit ?? '',
        daily_limit: setting.daily_limit ?? '',
        track_opens: setting.track_opens,
        track_clicks: setting.track_clicks,
        footer_text: setting.footer_text ?? '',
    });
    const [testTo, setTestTo] = useState('');
    const [testing, setTesting] = useState(false);
    const [checkingDns, setCheckingDns] = useState(false);

    const changeProvider = (provider: Provider) => {
        const preset = presetFor(provider, data.aws_region);
        setData((current) => ({ ...current, provider, ...(preset ?? {}) }));
    };

    const changeRegion = (region: string) => {
        setData((current) => ({ ...current, aws_region: region, ...(current.provider === 'ses' ? presetFor('ses', region) : {}) }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('mail-settings.update', profile), { preserveScroll: true, onSuccess: () => setData('password', '') });
    };

    const sendTest: FormEventHandler = (e) => {
        e.preventDefault();
        setTesting(true);
        router.post(route('mail-settings.test', profile), { to: testTo }, { preserveScroll: true, onFinish: () => setTesting(false) });
    };

    const checkDns = () => {
        setCheckingDns(true);
        router.post(route('mail-settings.dns', profile), {}, { preserveScroll: true, onFinish: () => setCheckingDns(false) });
    };

    return (
        <div className="space-y-8">
            <div className="space-y-1">
                <p className="text-muted-foreground text-sm">{description}</p>
                <StatusChip setting={setting} />
                {setting.last_test_error && <p className="text-destructive text-sm">Última prueba fallida: {setting.last_test_error}</p>}
            </div>

            <form onSubmit={submit} className="space-y-8" noValidate>
                <fieldset className="space-y-4">
                    <legend className="text-sm font-semibold">Servidor</legend>

                    <Field id={`${profile}-provider`} label="Proveedor" error={errors.provider}>
                        <select
                            id={`${profile}-provider`}
                            className={selectClass}
                            value={data.provider}
                            onChange={(e) => changeProvider(e.target.value as Provider)}
                        >
                            {PROVIDERS.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    {data.provider === 'ses' && (
                        <Field id={`${profile}-region`} label="Región de AWS" error={errors.aws_region} hint="Por ejemplo us-east-1">
                            <Input id={`${profile}-region`} value={data.aws_region} onChange={(e) => changeRegion(e.target.value.trim())} />
                        </Field>
                    )}

                    <div className="grid gap-4 sm:grid-cols-[1fr_8rem_9rem]">
                        <Field id={`${profile}-host`} label="Servidor SMTP" required error={errors.host}>
                            <Input id={`${profile}-host`} value={data.host} onChange={(e) => setData('host', e.target.value.trim())} />
                        </Field>
                        <Field id={`${profile}-port`} label="Puerto" required error={errors.port}>
                            <select
                                id={`${profile}-port`}
                                className={selectClass}
                                value={data.port}
                                onChange={(e) => setData('port', Number(e.target.value))}
                            >
                                {[587, 465, 2587, 2465, 25].map((port) => (
                                    <option key={port} value={port}>
                                        {port}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field id={`${profile}-encryption`} label="Cifrado" required error={errors.encryption}>
                            <select
                                id={`${profile}-encryption`}
                                className={selectClass}
                                value={data.encryption}
                                onChange={(e) => setData('encryption', e.target.value as 'tls' | 'ssl')}
                            >
                                <option value="tls">STARTTLS</option>
                                <option value="ssl">SSL/TLS</option>
                            </select>
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id={`${profile}-username`} label="Usuario" error={errors.username}>
                            <Input
                                id={`${profile}-username`}
                                autoComplete="off"
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                            />
                        </Field>
                        <Field
                            id={`${profile}-password`}
                            label="Contraseña o clave"
                            error={errors.password}
                            hint={setting.password_hint ? `Guardada: ${setting.password_hint}. Déjala vacía para conservarla.` : undefined}
                        >
                            <Input
                                id={`${profile}-password`}
                                type="password"
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                        </Field>
                    </div>
                </fieldset>

                <fieldset className="space-y-4">
                    <legend className="text-sm font-semibold">Remitente</legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id={`${profile}-from-name`} label="Nombre" required error={errors.from_name}>
                            <Input id={`${profile}-from-name`} value={data.from_name} onChange={(e) => setData('from_name', e.target.value)} />
                        </Field>
                        <Field id={`${profile}-from-address`} label="Correo" required error={errors.from_address}>
                            <Input
                                id={`${profile}-from-address`}
                                type="email"
                                value={data.from_address}
                                onChange={(e) => setData('from_address', e.target.value.trim())}
                            />
                        </Field>
                        <Field id={`${profile}-reply-to`} label="Responder a" error={errors.reply_to}>
                            <Input
                                id={`${profile}-reply-to`}
                                type="email"
                                value={data.reply_to}
                                onChange={(e) => setData('reply_to', e.target.value.trim())}
                            />
                        </Field>
                        <Field
                            id={`${profile}-dkim`}
                            label="Selector DKIM"
                            error={errors.dkim_selector}
                            hint="Lo indica tu proveedor, por ejemplo «resend» o «mail»."
                        >
                            <Input
                                id={`${profile}-dkim`}
                                value={data.dkim_selector}
                                onChange={(e) => setData('dkim_selector', e.target.value.trim())}
                            />
                        </Field>
                    </div>
                </fieldset>

                <fieldset className="space-y-4">
                    <legend className="text-sm font-semibold">Límites y seguimiento</legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id={`${profile}-hourly`} label="Envíos por hora" error={errors.hourly_limit}>
                            <Input
                                id={`${profile}-hourly`}
                                type="number"
                                min={1}
                                value={data.hourly_limit}
                                onChange={(e) => setData('hourly_limit', e.target.value === '' ? '' : Number(e.target.value))}
                            />
                        </Field>
                        <Field id={`${profile}-daily`} label="Envíos por día" error={errors.daily_limit}>
                            <Input
                                id={`${profile}-daily`}
                                type="number"
                                min={1}
                                value={data.daily_limit}
                                onChange={(e) => setData('daily_limit', e.target.value === '' ? '' : Number(e.target.value))}
                            />
                        </Field>
                    </div>
                    <div className="flex flex-wrap gap-6">
                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox checked={data.track_opens} onCheckedChange={(v) => setData('track_opens', v === true)} />
                            Medir aperturas (estimadas)
                        </label>
                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox checked={data.track_clicks} onCheckedChange={(v) => setData('track_clicks', v === true)} />
                            Medir clics
                        </label>
                    </div>
                    <div className="space-y-1 text-sm">
                        <p className="font-medium">Webhook de eventos del proveedor</p>
                        <p className="text-muted-foreground">
                            Se habilita con el módulo de campañas. Entonces pegarás esta URL en tu proveedor para recibir rebotes, quejas y bajas:{' '}
                            <code className="break-all">{webhookUrl}</code>
                        </p>
                    </div>
                </fieldset>

                <Field
                    id={`${profile}-footer`}
                    label="Pie legal"
                    required={profile === 'broadcast'}
                    error={errors.footer_text}
                    hint="Autorización de tratamiento de datos (Ley 1581 de 2012), dirección y enlace de baja."
                >
                    <Textarea id={`${profile}-footer`} value={data.footer_text} onChange={(e) => setData('footer_text', e.target.value)} />
                </Field>

                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden />}
                    Guardar
                </Button>
            </form>

            <section className="space-y-4 border-t pt-6" aria-labelledby={`${profile}-test-title`}>
                <h4 id={`${profile}-test-title`} className="text-sm font-semibold">
                    Probar y activar
                </h4>
                <form onSubmit={sendTest} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <Field id={`${profile}-test-to`} label="Enviar prueba a" className="flex-1">
                        <Input id={`${profile}-test-to`} type="email" required value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                    </Field>
                    <Button type="submit" variant="secondary" disabled={testing || !testTo}>
                        {testing && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden />}
                        Enviar correo de prueba
                    </Button>
                </form>
            </section>

            <section className="space-y-4 border-t pt-6" aria-labelledby={`${profile}-dns-title`}>
                <div className="flex items-center justify-between gap-4">
                    <h4 id={`${profile}-dns-title`} className="text-sm font-semibold">
                        Dominio del remitente
                    </h4>
                    <Button type="button" variant="outline" size="sm" onClick={checkDns} disabled={checkingDns}>
                        {checkingDns && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden />}
                        Verificar SPF, DKIM y DMARC
                    </Button>
                </div>
                {dns && (
                    <ul className="space-y-2 text-sm" aria-label={`Registros DNS de ${dns.domain}`}>
                        <DnsRow label="SPF" ok={dns.spf} hint={`Registro TXT en ${dns.domain} que empiece por v=spf1 e incluya a tu proveedor.`} />
                        <DnsRow label="DKIM" ok={dns.dkim} hint="Registro que entrega tu proveedor para el selector indicado." />
                        <DnsRow label="DMARC" ok={dns.dmarc} hint={`Registro TXT en _dmarc.${dns.domain}, por ejemplo v=DMARC1; p=none;`} />
                    </ul>
                )}
            </section>
        </div>
    );
}

function DnsRow({ label, ok, hint }: { label: string; ok: boolean | null; hint: string }) {
    if (ok === null) {
        return (
            <li className="text-muted-foreground flex items-start gap-2">
                <CircleDashed className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                <span>
                    <strong>{label}:</strong> sin selector, no se verificó.
                </span>
            </li>
        );
    }

    return (
        <li className={`flex items-start gap-2 ${ok ? 'text-success' : 'text-destructive'}`}>
            {ok ? <CircleCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden /> : <CircleX className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />}
            <span>
                <strong>{label}:</strong> {ok ? 'encontrado' : `falta. ${hint}`}
            </span>
        </li>
    );
}

function Field({
    id,
    label,
    required,
    error,
    hint,
    className,
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={`grid content-start gap-2 ${className ?? ''}`}>
            <Label htmlFor={id}>
                {label}
                {required && (
                    <span className="text-destructive" aria-hidden>
                        {' '}
                        *
                    </span>
                )}
            </Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

function formatDate(iso: string | null) {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(iso));
}
