import Flash from '@/components/campaigns/flash';
import { type CampaignStats, type CampaignStatus, percent, StatusBadge } from '@/components/campaigns/types';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { dateTime, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FileText, LayoutTemplate, Plus, TriangleAlert } from 'lucide-react';

interface CampaignRow extends CampaignStats {
    id: number;
    name: string;
    subject: string;
    status: CampaignStatus;
    segment: string[];
    scheduled_at: string | null;
    started_at: string | null;
    updated_at: string;
    creator: string | null;
}

interface PageProps {
    campaigns: {
        data: CampaignRow[];
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    templates: { id: number; name: string }[];
    canEdit: boolean;
    senderReady: boolean;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Campañas', href: '/campaigns' }];

export default function CampaignsIndex() {
    const { campaigns, templates, canEdit, senderReady } = usePage<PageProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Campañas" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Campañas" description="Boletines y anuncios por correo a los contactos que aceptan difusiones." />
                    {canEdit && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/campaigns/templates">
                                    <LayoutTemplate aria-hidden />
                                    Plantillas
                                </Link>
                            </Button>
                            <NewCampaignDialog templates={templates} />
                        </div>
                    )}
                </div>

                <Flash />

                {canEdit && !senderReady && (
                    <Alert>
                        <TriangleAlert className="h-4 w-4" aria-hidden />
                        <AlertDescription>
                            Para enviar, primero configura y verifica el correo de difusión en{' '}
                            <Link href="/settings/mail" className="font-medium underline underline-offset-4">
                                Configuración › Correo de envíos
                            </Link>
                            . Mientras tanto puedes preparar borradores.
                        </AlertDescription>
                    </Alert>
                )}

                {campaigns.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        Aún no hay campañas. {canEdit ? 'Crea la primera desde una plantilla.' : ''}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[920px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    {['Campaña', 'Estado', 'Público', 'Enviados', 'Aperturas', 'Clics', 'Fecha'].map((h) => (
                                        <th key={h} scope="col" className="px-4 py-3 font-medium whitespace-nowrap">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {campaigns.data.map((c) => (
                                    <tr key={c.id} className="align-top">
                                        <td className="px-4 py-3">
                                            <Link href={`/campaigns/${c.id}`} className="font-medium underline-offset-4 hover:underline">
                                                {c.name}
                                            </Link>
                                            <div className="text-muted-foreground line-clamp-1 text-xs">{c.subject || 'Sin asunto'}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={c.status} />
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3 text-xs">{c.segment.join(' · ')}</td>
                                        <td className="px-4 py-3 whitespace-nowrap tabular-nums">
                                            {c.recipients ? `${number.format(c.sent)} de ${number.format(c.recipients)}` : '—'}
                                            {c.failed > 0 && <div className="text-destructive text-xs">{number.format(c.failed)} fallidos</div>}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">{c.sent ? percent(c.opened, c.sent) : '—'}</td>
                                        <td className="px-4 py-3 tabular-nums">{c.sent ? percent(c.clicked, c.sent) : '—'}</td>
                                        <td className="text-muted-foreground px-4 py-3 text-xs whitespace-nowrap">
                                            {c.status === 'scheduled' && c.scheduled_at
                                                ? `Sale el ${dateTime.format(new Date(c.scheduled_at))}`
                                                : c.started_at
                                                  ? dateTime.format(new Date(c.started_at))
                                                  : `Editada ${dateTime.format(new Date(c.updated_at))}`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {campaigns.last_page > 1 && (
                    <nav className="flex justify-end gap-2" aria-label="Paginación">
                        {[
                            { href: campaigns.prev_page_url, label: 'Anterior', icon: ChevronLeft },
                            { href: campaigns.next_page_url, label: 'Siguiente', icon: ChevronRight },
                        ].map(({ href, label, icon: Icon }) =>
                            href ? (
                                <Button key={label} variant="outline" size="icon" asChild>
                                    <Link href={href} preserveScroll aria-label={label}>
                                        <Icon aria-hidden />
                                    </Link>
                                </Button>
                            ) : (
                                <Button key={label} variant="outline" size="icon" disabled aria-label={label}>
                                    <Icon aria-hidden />
                                </Button>
                            ),
                        )}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}

function NewCampaignDialog({ templates }: { templates: { id: number; name: string }[] }) {
    const create = (templateId?: number) => router.post('/campaigns', templateId ? { template_id: templateId } : {});

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button>
                    <Plus aria-hidden />
                    Nueva campaña
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nueva campaña</DialogTitle>
                    <DialogDescription>
                        Empieza desde una plantilla o con un correo en blanco. Nada se envía hasta que lo confirmes.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    {templates.map((t) => (
                        <Button key={t.id} variant="outline" className="justify-start" onClick={() => create(t.id)}>
                            <LayoutTemplate aria-hidden />
                            {t.name}
                        </Button>
                    ))}
                    <Button variant="ghost" className="justify-start" onClick={() => create()}>
                        <FileText aria-hidden />
                        En blanco
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
