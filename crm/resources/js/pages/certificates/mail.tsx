import ContentEditor from '@/components/campaigns/content-editor';
import Flash from '@/components/campaigns/flash';
import TestDialog from '@/components/campaigns/test-dialog';
import { type Content } from '@/components/campaigns/types';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, Save } from 'lucide-react';
import { FormEventHandler } from 'react';

interface PageProps extends SharedData {
    template: Content & { updated_at: string | null };
    fields: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Certificados', href: '/certificados' },
    { title: 'Correo del certificado', href: '/certificados/correo' },
];

export default function CertificateMailEditor() {
    const { template, fields, auth } = usePage<PageProps>().props;

    const { data, setData, put, processing, errors, isDirty } = useForm<Content>({
        name: template.name,
        subject: template.subject ?? '',
        preheader: template.preheader ?? '',
        body: template.body ?? '',
        body_format: template.body_format ?? 'markdown',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/certificados/correo', { preserveScroll: true });
    };

    const sendTest = (email: string) => {
        const send = () => router.post('/certificados/correo/prueba', { email }, { preserveScroll: true });
        if (isDirty) put('/certificados/correo', { preserveScroll: true, onSuccess: send });
        else send();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Correo del certificado" />

            <form onSubmit={submit} className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Correo del certificado"
                        description={`Lo recibe cada persona con su certificado en PDF adjunto. ${
                            template.updated_at ? `Editado ${dateTime.format(new Date(template.updated_at))}.` : ''
                        }`}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" variant="outline" disabled={processing}>
                            {processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Save aria-hidden />}
                            {isDirty ? 'Guardar' : 'Guardado'}
                        </Button>
                        <TestDialog
                            description="Llega solo a este correo, con datos de ejemplo y un certificado de muestra. Antes se guardan los cambios."
                            defaultEmail={auth.user.email}
                            disabled={processing}
                            onSend={sendTest}
                        />
                    </div>
                </div>

                <Flash />

                <ContentEditor
                    data={data}
                    setData={setData}
                    errors={errors}
                    fields={fields}
                    showName={false}
                    blockFields={['certificado']}
                    previewUrl="/certificados/correo/vista-previa"
                    previewNote="Con datos de ejemplo (María Pérez, Conferencias). Cada persona ve su nombre y su actividad."
                    footerNote={
                        <>
                            <code>{'{{certificado}}'}</code> pone un botón a la página donde se verifica y se descarga de nuevo; va en su propia
                            línea. El PDF siempre va adjunto.
                        </>
                    }
                />
            </form>
        </AppLayout>
    );
}
