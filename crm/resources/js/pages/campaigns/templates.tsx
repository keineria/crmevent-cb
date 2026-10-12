import Flash from '@/components/campaigns/flash';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { LayoutTemplate, Plus } from 'lucide-react';

interface PageProps {
    templates: { id: number; name: string; subject: string; preheader: string | null; updated_at: string }[];
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Campañas', href: '/campaigns' },
    { title: 'Plantillas', href: '/campaigns/templates' },
];

export default function Templates() {
    const { templates } = usePage<PageProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Plantillas" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Plantillas"
                        description="Correos base para empezar campañas. Cambiar una plantilla no afecta campañas ya creadas."
                    />
                    <Button onClick={() => router.post('/campaigns/templates')}>
                        <Plus aria-hidden />
                        Nueva plantilla
                    </Button>
                </div>

                <Flash />

                {templates.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">Aún no hay plantillas.</div>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {templates.map((t) => (
                            <li key={t.id}>
                                <Link
                                    href={`/campaigns/templates/${t.id}/edit`}
                                    className="hover:bg-muted/40 focus-visible:ring-ring flex h-full flex-col gap-2 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    <span className="flex items-center gap-2 font-medium">
                                        <LayoutTemplate className="text-primary h-4 w-4" aria-hidden />
                                        {t.name}
                                    </span>
                                    <span className="text-sm">{t.subject || 'Sin asunto'}</span>
                                    {t.preheader && <span className="text-muted-foreground line-clamp-2 text-xs">{t.preheader}</span>}
                                    <span className="text-muted-foreground mt-auto text-xs">Editada {dateTime.format(new Date(t.updated_at))}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
