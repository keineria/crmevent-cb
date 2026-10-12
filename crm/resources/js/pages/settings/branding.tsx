import Flash from '@/components/campaigns/flash';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ImageUp, LoaderCircle, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

type Kind = 'platform' | 'login' | 'email';

interface Logo {
    url: string | null;
    own: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Marca y logos', href: '/settings/branding' }];

const KINDS: { key: Kind; title: string; description: string; accept: string; hint: string; preview: 'sidebar' | 'login' | 'email' }[] = [
    {
        key: 'platform',
        title: 'Logo de la plataforma',
        description: 'Arriba del menú lateral. Si no subes los otros dos, también se usa en el inicio de sesión y en los correos.',
        accept: 'image/png,image/jpeg,image/webp',
        hint: 'PNG, JPG o WebP, máximo 2 MB. Horizontal y con fondo transparente se ve mejor; se muestra a 32 px de alto.',
        preview: 'sidebar',
    },
    {
        key: 'login',
        title: 'Logo del inicio de sesión',
        description: 'En la página de entrada, recuperar contraseña y el portal de inscritos.',
        accept: 'image/png,image/jpeg,image/webp',
        hint: 'PNG, JPG o WebP, máximo 2 MB. Se muestra a 56 px de alto.',
        preview: 'login',
    },
    {
        key: 'email',
        title: 'Logo de los correos',
        description: 'Encabezado de las campañas, plantillas y el correo con el código QR.',
        accept: 'image/png,image/jpeg',
        hint: 'PNG o JPG (Outlook no muestra WebP), máximo 2 MB. Se muestra a 180 px de ancho sobre fondo claro.',
        preview: 'email',
    },
];

function LogoCard({ kind, logo }: { kind: (typeof KINDS)[number]; logo: Logo }) {
    const input = useRef<HTMLInputElement>(null);
    const [processing, setProcessing] = useState(false);
    // Each card has its own error bag, so an error shows under the logo it belongs to.
    const errors = (usePage().props.errors as Record<string, Record<string, string> | undefined>)[kind.key] ?? {};

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }
        router.post(
            `/settings/branding/${kind.key}`,
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                errorBag: kind.key,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    const remove = () => {
        router.delete(`/settings/branding/${kind.key}`, { preserveScroll: true });
    };

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div>
                <h3 className="font-medium">{kind.title}</h3>
                <p className="text-muted-foreground text-sm">{kind.description}</p>
            </div>

            <div
                className={
                    kind.preview === 'email'
                        ? 'flex h-24 items-center rounded-lg bg-[#F6F3EE] px-4'
                        : kind.preview === 'login'
                          ? 'bg-background flex h-24 items-center justify-center rounded-lg border'
                          : 'bg-sidebar flex h-24 items-center rounded-lg border px-4'
                }
            >
                {logo.url ? (
                    <img
                        src={logo.url}
                        alt={kind.title}
                        className={
                            kind.preview === 'email'
                                ? 'max-h-16 w-[180px] object-contain object-left'
                                : kind.preview === 'login'
                                  ? 'h-14 max-w-[240px] object-contain'
                                  : 'h-8 max-w-[180px] object-contain object-left'
                        }
                    />
                ) : (
                    <p className={kind.preview === 'email' ? 'text-sm text-[#6B655C]' : 'text-muted-foreground text-sm'}>
                        {kind.preview === 'email' ? 'Sin logo: se escribe «Colombia Birdfair».' : 'Sin logo: se muestra el ícono del ave.'}
                    </p>
                )}
            </div>
            {logo.url && !logo.own && <p className="text-muted-foreground text-xs">Usando el logo de la plataforma.</p>}

            <div className="flex flex-wrap items-center gap-2">
                <input
                    ref={input}
                    type="file"
                    accept={kind.accept}
                    className="sr-only"
                    id={`logo-${kind.key}`}
                    onChange={(e) => upload(e.target.files?.[0])}
                />
                <Button type="button" variant="outline" size="sm" disabled={processing} onClick={() => input.current?.click()}>
                    {processing ? <LoaderCircle className="size-4 animate-spin" /> : <ImageUp className="size-4" />}
                    {logo.own ? 'Cambiar logo' : 'Subir logo'}
                </Button>
                {logo.own && (
                    <Button type="button" variant="ghost" size="sm" onClick={remove}>
                        <Trash2 className="size-4" /> Quitar
                    </Button>
                )}
            </div>
            <p className="text-muted-foreground text-xs">{kind.hint}</p>
            <InputError message={errors.logo} />
        </section>
    );
}

export default function Branding({ logos }: { logos: Record<Kind, Logo> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Marca y logos" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Marca y logos" description="Los logos que ven el equipo, los inscritos y quienes reciben los correos." />
                    <Flash />
                    {KINDS.map((kind) => (
                        <LogoCard key={kind.key} kind={kind} logo={logos[kind.key]} />
                    ))}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
