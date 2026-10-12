import Flash from '@/components/campaigns/flash';
import TemplateThumb from '@/components/certificates/template-thumb';
import { selectClass } from '@/components/finance/period-filter';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { day, money, number } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Award, CheckCircle2, ChevronRight, Link2, Mail, Palette, Plus } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface CertificateRow {
    id: number;
    activity_name: string;
    activity_date: string | null;
    hours: number | null;
    require_check_in: boolean;
    sent: number;
    sent_at: string | null;
}

interface Category {
    id: number;
    name: string;
    activity_type: string;
    template: string;
    primary_color: string;
    accent_color: string;
    has_logo: boolean;
    has_background: boolean;
    certificates: CertificateRow[];
}

interface Activity {
    id: number;
    type: string;
    name: string;
    date: string | null;
    start_time: string | null;
    price: number;
}

interface PageProps {
    categories: Category[];
    activities: Activity[];
    taken: Record<number, number[]>;
    types: Record<string, string>;
    templates: Record<string, string>;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Certificados', href: '/certificados' }];

function NewCategory({ types }: { types: Record<string, string> }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ name: '', activity_type: 'workshop' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/certificados/categorias');
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus aria-hidden />
                    Nueva categoría
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Nueva categoría de certificados</DialogTitle>
                        <DialogDescription>
                            Una categoría es un diseño (logo, colores, texto y firmas) que comparten varias actividades. Después la asocias a los
                            talleres o a las conferencias.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="cat-name">Nombre</Label>
                        <Input
                            id="cat-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Talleres 2027"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cat-type">Para</Label>
                        <select
                            id="cat-type"
                            className={selectClass}
                            value={data.activity_type}
                            onChange={(e) => setData('activity_type', e.target.value)}
                        >
                            {Object.entries(types).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.activity_type} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" disabled={processing || !data.name.trim()}>
                            Crear y diseñar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Associate({ category, activities, taken, typeLabel }: { category: Category; activities: Activity[]; taken: number[]; typeLabel: string }) {
    const [open, setOpen] = useState(false);
    const available = activities.filter((a) => a.type === category.activity_type && !taken.includes(a.id));
    const { data, setData, post, processing, errors, reset } = useForm<{
        category: number;
        activities: number[];
        hours: string;
        require_check_in: boolean;
    }>({ category: category.id, activities: [], hours: '', require_check_in: true });

    const toggle = (id: number, on: boolean) => setData('activities', on ? [...data.activities, id] : data.activities.filter((x) => x !== id));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/certificados', {
            preserveScroll: true,
            onSuccess: () => {
                reset('activities', 'hours');
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Link2 aria-hidden />
                    Asociar actividades
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Asociar a {category.name}</DialogTitle>
                        <DialogDescription>
                            Cada actividad que marques tendrá su propio certificado con este diseño y su propio botón de enviar.
                        </DialogDescription>
                    </DialogHeader>

                    {available.length === 0 ? (
                        <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">
                            No quedan {typeLabel.toLowerCase()} sin certificado en esta categoría.
                        </p>
                    ) : (
                        <fieldset className="grid gap-1">
                            <legend className="mb-2 text-sm font-medium">{typeLabel}</legend>
                            {available.map((a) => (
                                <label key={a.id} className="hover:bg-muted/40 flex cursor-pointer items-start gap-3 rounded-md p-2 text-sm">
                                    <Checkbox
                                        checked={data.activities.includes(a.id)}
                                        onCheckedChange={(v) => toggle(a.id, v === true)}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">{a.name}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {day(a.date)}
                                            {a.start_time ? ` · ${a.start_time}` : ''} · {a.price > 0 ? money.format(a.price) : 'Sin costo'}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <InputError message={errors.activities} />

                    <div className="grid gap-2 sm:max-w-48">
                        <Label htmlFor={`hours-${category.id}`}>Horas (opcional)</Label>
                        <Input
                            id={`hours-${category.id}`}
                            type="number"
                            min="0.5"
                            step="0.5"
                            inputMode="decimal"
                            value={data.hours}
                            onChange={(e) => setData('hours', e.target.value)}
                        />
                        <InputError message={errors.hours} />
                    </div>

                    <label className="flex items-start gap-3 text-sm">
                        <Checkbox
                            checked={data.require_check_in}
                            onCheckedChange={(v) => setData('require_check_in', v === true)}
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-medium">Solo para quienes hicieron check-in</span>
                            <span className="text-muted-foreground block">
                                Si lo desmarcas, lo recibe todo el que pagó la actividad aunque no haya llegado.
                            </span>
                        </span>
                    </label>

                    <DialogFooter>
                        <Button type="submit" disabled={processing || data.activities.length === 0}>
                            Crear {data.activities.length > 1 ? `${data.activities.length} certificados` : 'certificado'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function CertificatesIndex() {
    const { categories, activities, taken, types, templates } = usePage<PageProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Certificados" />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Certificados de asistencia"
                        description="Diseña una categoría, asóciala a talleres o conferencias y envía cada certificado cuando lo decidan. Nada sale sin pulsar Enviar."
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/certificados/correo">
                                <Mail aria-hidden />
                                Correo del certificado
                            </Link>
                        </Button>
                        <NewCategory types={types} />
                    </div>
                </div>

                <Flash />

                {categories.length === 0 ? (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center text-sm">
                        <Award className="text-primary h-8 w-8" aria-hidden />
                        Aún no hay categorías. Crea una para talleres y otra para conferencias.
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {categories.map((c) => (
                            <section key={c.id} className="rounded-xl border">
                                <div className="flex flex-wrap items-center gap-4 border-b p-4">
                                    <TemplateThumb
                                        template={c.template}
                                        primary={c.primary_color}
                                        accent={c.accent_color}
                                        className="w-28 shrink-0"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <h2 className="flex flex-wrap items-center gap-2 font-medium">
                                            {c.name}
                                            <Badge variant="secondary">{types[c.activity_type] ?? c.activity_type}</Badge>
                                        </h2>
                                        <p className="text-muted-foreground text-sm">
                                            Diseño {templates[c.template] ?? c.template}
                                            {c.has_background ? ' con imagen de fondo' : ''}
                                            {c.has_logo ? '' : ' · sin logo'} · {number.format(c.certificates.length)}{' '}
                                            {c.certificates.length === 1 ? 'actividad' : 'actividades'}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={`/certificados/categorias/${c.id}`}>
                                                <Palette aria-hidden />
                                                Editar diseño
                                            </Link>
                                        </Button>
                                        <Associate
                                            category={c}
                                            activities={activities}
                                            taken={taken[c.id] ?? []}
                                            typeLabel={types[c.activity_type] ?? ''}
                                        />
                                    </div>
                                </div>

                                {c.certificates.length === 0 ? (
                                    <p className="text-muted-foreground p-4 text-sm">Sin actividades asociadas todavía.</p>
                                ) : (
                                    <ul className="divide-y">
                                        {c.certificates.map((cert) => (
                                            <li key={cert.id}>
                                                <Link
                                                    href={`/certificados/${cert.id}`}
                                                    className="hover:bg-muted/40 focus-visible:ring-ring flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                                >
                                                    <span className="min-w-0 flex-1 font-medium">{cert.activity_name}</span>
                                                    <span className="text-muted-foreground">{day(cert.activity_date)}</span>
                                                    {cert.hours !== null && <span className="text-muted-foreground">{cert.hours} h</span>}
                                                    <span className="text-muted-foreground">
                                                        {cert.require_check_in ? 'Con check-in' : 'Todos los pagados'}
                                                    </span>
                                                    {cert.sent > 0 ? (
                                                        <span className="text-success flex items-center gap-1">
                                                            <CheckCircle2 className="h-4 w-4" aria-hidden />
                                                            {number.format(cert.sent)} enviados
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">Sin enviar</span>
                                                    )}
                                                    <ChevronRight className="text-muted-foreground h-4 w-4" aria-hidden />
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
