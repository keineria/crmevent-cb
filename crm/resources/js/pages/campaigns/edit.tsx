import ContentEditor from '@/components/campaigns/content-editor';
import Flash from '@/components/campaigns/flash';
import SegmentBuilder, { type SegmentOptions } from '@/components/campaigns/segment-builder';
import TestDialog from '@/components/campaigns/test-dialog';
import { type CampaignStatus, type Content, type Segment, StatusBadge } from '@/components/campaigns/types';
import { crmJson } from '@/components/checkin/types';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, LayoutTemplate, LoaderCircle, Save, Send, Trash2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface PageProps {
    campaign: Content & { id: number; segment: Segment; status: CampaignStatus; scheduled_at: string | null };
    options: SegmentOptions;
    fields: Record<string, string>;
    sender: { ready: boolean; from: string | null; hourly: number; daily: number | null };
    testEmail: string;
    [key: string]: unknown;
}

type Form = Content & { segment: Segment };

export default function CampaignEdit() {
    const { campaign, options, fields, sender, testEmail } = usePage<PageProps>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Campañas', href: '/campaigns' },
        { title: campaign.name, href: `/campaigns/${campaign.id}/edit` },
    ];

    const { data, setData, put, processing, errors, isDirty } = useForm<Form>({
        name: campaign.name,
        subject: campaign.subject ?? '',
        preheader: campaign.preheader ?? '',
        body: campaign.body ?? '',
        segment: campaign.segment ?? {},
    });

    /** Saves, then runs `next` (test send, launch…) on the saved version. */
    const save = (next?: () => void) => put(`/campaigns/${campaign.id}`, { preserveScroll: true, onSuccess: () => next?.() });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        save();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={campaign.name} />

            <form onSubmit={submit} className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                        <Heading title={campaign.name} description="Escribe el correo, elige a quién le llega y envíalo o prográmalo." />
                        <StatusBadge status={campaign.status} />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" variant="outline" disabled={processing}>
                            {processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Save aria-hidden />}
                            {isDirty ? 'Guardar' : 'Guardado'}
                        </Button>
                        <TestDialog
                            description="Llega solo a este correo, con datos de ejemplo y «[Prueba]» en el asunto. No cuenta en las estadísticas."
                            defaultEmail={testEmail}
                            disabled={!sender.ready}
                            onSend={(email) => save(() => router.post(`/campaigns/${campaign.id}/test`, { email }, { preserveScroll: true }))}
                        />
                        <LaunchDialog
                            segment={data.segment}
                            sender={sender}
                            scheduledAt={campaign.scheduled_at}
                            onLaunch={(scheduled_at) => save(() => router.post(`/campaigns/${campaign.id}/launch`, { scheduled_at }))}
                        />
                    </div>
                </div>

                <Flash />

                {!sender.ready && (
                    <Alert>
                        <TriangleAlert className="h-4 w-4" aria-hidden />
                        <AlertDescription>
                            El correo de difusión aún no está verificado. Puedes preparar la campaña, pero para probarla o enviarla primero
                            configúralo en Configuración › Correo de envíos.
                        </AlertDescription>
                    </Alert>
                )}
                {campaign.status === 'scheduled' && (
                    <Alert>
                        <CalendarClock className="h-4 w-4" aria-hidden />
                        <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
                            Está programada. Los cambios que guardes se enviarán en la fecha elegida.
                            <Button type="button" variant="outline" size="sm" onClick={() => router.post(`/campaigns/${campaign.id}/cancel`)}>
                                Quitar programación
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                <section aria-labelledby="content-title" className="grid gap-4">
                    <h2 id="content-title" className="text-lg font-semibold">
                        1. Contenido
                    </h2>
                    <ContentEditor data={data} setData={setData} errors={errors} fields={fields} />
                </section>

                <section aria-labelledby="segment-title" className="grid max-w-3xl gap-4">
                    <h2 id="segment-title" className="text-lg font-semibold">
                        2. Público
                    </h2>
                    <SegmentBuilder value={data.segment} onChange={(s) => setData('segment', s)} options={options} />
                </section>

                <div className="flex flex-wrap gap-2 border-t pt-4">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => save(() => router.post('/campaigns/templates', { from_campaign: campaign.id }))}
                        disabled={processing}
                    >
                        <LayoutTemplate aria-hidden />
                        Guardar como plantilla
                    </Button>
                    {campaign.status === 'draft' && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => confirm('¿Borrar este borrador?') && router.delete(`/campaigns/${campaign.id}`)}
                        >
                            <Trash2 aria-hidden />
                            Borrar borrador
                        </Button>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}

function LaunchDialog({
    segment,
    sender,
    scheduledAt,
    onLaunch,
}: {
    segment: Segment;
    sender: PageProps['sender'];
    scheduledAt: string | null;
    onLaunch: (scheduledAt: string | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<'now' | 'later'>(scheduledAt ? 'later' : 'now');
    const [when, setWhen] = useState(scheduledAt ?? '');
    const [preview, setPreview] = useState<{ count: number; summary: string[] } | null>(null);
    const [error, setError] = useState<string | null>(null);

    const openDialog = (next: boolean) => {
        setOpen(next);
        if (next) {
            setPreview(null);
            crmJson<{ count: number; summary: string[] }>('/campaigns/segment/count', { segment })
                .then(setPreview)
                .catch((e: Error) => setError(e.message));
        }
    };

    const hours = preview ? Math.ceil(preview.count / sender.hourly) : 0;

    return (
        <Dialog open={open} onOpenChange={openDialog}>
            <DialogTrigger asChild>
                <Button type="button" disabled={!sender.ready}>
                    <Send aria-hidden />
                    Enviar…
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Enviar la campaña</DialogTitle>
                    <DialogDescription>Revisa a quién le llega antes de confirmar. Después no se puede deshacer lo que ya salió.</DialogDescription>
                </DialogHeader>

                {error && <InputError message={error} />}
                {preview ? (
                    <div className="grid gap-3 text-sm">
                        <p>
                            Llegará a <strong className="tabular-nums">{number.format(preview.count)}</strong> personas
                            {sender.from && (
                                <>
                                    {' '}
                                    desde <span className="font-medium">{sender.from}</span>
                                </>
                            )}
                            .
                        </p>
                        <ul className="text-muted-foreground list-disc pl-5">
                            {preview.summary.map((s) => (
                                <li key={s}>{s}</li>
                            ))}
                        </ul>
                        <p className="text-muted-foreground">
                            Se envían hasta {number.format(sender.hourly)} por hora
                            {sender.daily ? ` y ${number.format(sender.daily)} por día` : ''}
                            {hours > 1 ? `, así que tardará unas ${hours} horas en salir completa.` : '.'}
                        </p>
                    </div>
                ) : (
                    !error && <LoaderCircle className="h-5 w-5 animate-spin" aria-label="Contando" />
                )}

                <fieldset className="grid gap-2">
                    <legend className="sr-only">Cuándo</legend>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="radio" name="when" checked={mode === 'now'} onChange={() => setMode('now')} />
                        Enviar ahora
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="radio" name="when" checked={mode === 'later'} onChange={() => setMode('later')} />
                        Programar (hora de Colombia)
                    </label>
                    {mode === 'later' && (
                        <Input type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} aria-label="Fecha y hora" />
                    )}
                </fieldset>

                <DialogFooter>
                    <Button
                        type="button"
                        disabled={!preview || preview.count === 0 || (mode === 'later' && !when)}
                        onClick={() => {
                            setOpen(false);
                            onLaunch(mode === 'later' ? when : null);
                        }}
                    >
                        {mode === 'later' ? <CalendarClock aria-hidden /> : <Send aria-hidden />}
                        {mode === 'later' ? 'Programar' : `Enviar a ${preview ? number.format(preview.count) : '…'}`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
