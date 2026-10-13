import ContentEditor from '@/components/campaigns/content-editor';
import Flash from '@/components/campaigns/flash';
import { type Content } from '@/components/campaigns/types';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, Save, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

interface PageProps {
    template: Content & { id: number };
    fields: Record<string, string>;
    [key: string]: unknown;
}

export default function TemplateEdit() {
    const { template, fields } = usePage<PageProps>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Campañas', href: '/campaigns' },
        { title: 'Plantillas', href: '/campaigns/templates' },
        { title: template.name, href: `/campaigns/templates/${template.id}/edit` },
    ];

    const { data, setData, put, processing, errors, isDirty } = useForm<Content>({
        name: template.name,
        subject: template.subject ?? '',
        preheader: template.preheader ?? '',
        body: template.body ?? '',
        body_format: template.body_format ?? 'markdown',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/campaigns/templates/${template.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={template.name} />

            <form onSubmit={submit} className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={template.name} description="Plantilla de correo" />
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => confirm('¿Borrar esta plantilla?') && router.delete(`/campaigns/templates/${template.id}`)}
                        >
                            <Trash2 aria-hidden />
                            Borrar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Save aria-hidden />}
                            {isDirty ? 'Guardar' : 'Guardado'}
                        </Button>
                    </div>
                </div>

                <Flash />

                <ContentEditor data={data} setData={setData} errors={errors} fields={fields} nameLabel="Nombre de la plantilla" />
            </form>
        </AppLayout>
    );
}
