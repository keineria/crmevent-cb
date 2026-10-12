import Flash from '@/components/campaigns/flash';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface Signer {
    id: number;
    name: string;
    role: string | null;
    image: string | null;
    categories: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Certificados', href: '/certificados' },
    { title: 'Firmantes', href: '/certificados/firmantes' },
];

function SignerDialog({ signer }: { signer?: Signer }) {
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<string | null>(signer?.image ?? null);
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        role: string;
        image: File | null;
        remove_image: boolean;
        _method?: string;
    }>({ name: signer?.name ?? '', role: signer?.role ?? '', image: null, remove_image: false, ...(signer ? { _method: 'put' } : {}) });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(signer ? `/certificados/firmantes/${signer.id}` : '/certificados/firmantes', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!signer) {
                    reset();
                    setPreview(null);
                }
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {signer ? (
                    <Button variant="ghost" size="sm">
                        <Pencil className="size-4" aria-hidden />
                        Editar
                    </Button>
                ) : (
                    <Button>
                        <Plus aria-hidden />
                        Nuevo firmante
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{signer ? `Editar a ${signer.name}` : 'Nuevo firmante'}</DialogTitle>
                        <DialogDescription>Su nombre, cargo y firma salen en los certificados de las categorías donde lo elijas.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="signer-name">Nombre</Label>
                        <Input id="signer-name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="signer-role">Cargo</Label>
                        <Input
                            id="signer-role"
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                            placeholder="Directora Colombia Birdfair"
                        />
                        <InputError message={errors.role} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="signer-image">Firma</Label>
                        <div className="flex h-24 items-center justify-center rounded-lg border bg-white p-2">
                            {preview && !data.remove_image ? (
                                <img src={preview} alt="Firma" className="max-h-full max-w-full object-contain" />
                            ) : (
                                <span className="text-sm text-neutral-500">Sin firma</span>
                            )}
                        </div>
                        <Input
                            id="signer-image"
                            type="file"
                            accept="image/png,image/jpeg"
                            onChange={(e) => {
                                const file = e.target.files?.[0] ?? null;
                                setData((d) => ({ ...d, image: file, remove_image: false }));
                                setPreview(file ? URL.createObjectURL(file) : (signer?.image ?? null));
                            }}
                        />
                        <p className="text-muted-foreground text-xs">
                            Firma en tinta oscura sobre papel blanco, foto o escaneo recortado. PNG con fondo transparente se ve mejor; máximo 2 MB.
                        </p>
                        {signer?.image && !data.image && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.remove_image} onChange={(e) => setData('remove_image', e.target.checked)} />
                                Quitar la firma actual
                            </label>
                        )}
                        <InputError message={errors.image} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" disabled={processing || !data.name.trim()}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Signers() {
    const { signers } = usePage<{ signers: Signer[] }>().props;

    const destroy = (s: Signer) => {
        const used = s.categories.length ? ` Se quitará de: ${s.categories.join(', ')}.` : '';
        if (window.confirm(`¿Borrar a ${s.name} como firmante?${used}`)) {
            router.delete(`/certificados/firmantes/${s.id}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Firmantes" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Firmantes"
                        description="Las personas que firman los certificados. Agrégalas una vez y elígelas en el diseño de cada categoría."
                    />
                    <SignerDialog />
                </div>

                <Flash />

                {signers.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">Aún no hay firmantes.</p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {signers.map((s) => (
                            <li key={s.id} className="grid gap-3 rounded-xl border p-4">
                                <div className="flex h-20 items-center justify-center rounded-lg border bg-white p-2">
                                    {s.image ? (
                                        <img src={s.image} alt={`Firma de ${s.name}`} className="max-h-full max-w-full object-contain" />
                                    ) : (
                                        <span className="text-sm text-neutral-500">Sin firma</span>
                                    )}
                                </div>
                                <div>
                                    <p className="font-medium">{s.name}</p>
                                    {s.role && <p className="text-muted-foreground text-sm">{s.role}</p>}
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {s.categories.length ? `Firma en: ${s.categories.join(', ')}` : 'Aún no está en ninguna categoría'}
                                    </p>
                                </div>
                                <div className="flex gap-1">
                                    <SignerDialog signer={s} />
                                    <Button variant="ghost" size="sm" onClick={() => destroy(s)}>
                                        <Trash2 className="size-4" aria-hidden />
                                        Borrar
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
