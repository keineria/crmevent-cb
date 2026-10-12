import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

type Status = 'activo' | 'desinscrito';

interface ContactValues {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    document_type: string;
    document_number: string;
    gender: string;
    country: string;
    state: string;
    city: string;
    organization: string;
    notes: string;
    profile: string;
    language: string;
    referral_source: string;
    is_attendee: boolean;
    is_interested: boolean;
    is_subscriber: boolean;
    products_auto: boolean;
    preferred_products: string[];
    status: Status;
    edition_year: string;
}

interface PageProps {
    contact: (Omit<ContactValues, 'edition_year'> & { id: number; name: string }) | null;
    options: { products: Record<string, string>; profiles: string[]; languages: string[]; referrals: string[] };
    defaultYear: number;
    [key: string]: unknown;
}

const EMPTY: ContactValues = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    document_type: 'CC',
    document_number: '',
    gender: '',
    country: 'Colombia',
    state: '',
    city: '',
    organization: '',
    notes: '',
    profile: '',
    language: 'Español',
    referral_source: '',
    is_attendee: false,
    is_interested: true,
    is_subscriber: false,
    products_auto: true,
    preferred_products: [],
    status: 'activo',
    edition_year: '',
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

export default function ContactForm() {
    const { contact, options, defaultYear } = usePage<PageProps>().props;
    const editing = contact !== null;
    const { data, setData, post, put, processing, errors } = useForm<ContactValues>(
        contact ? { ...EMPTY, ...contact, edition_year: '' } : { ...EMPTY, edition_year: String(defaultYear) },
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Contactos', href: '/contacts' },
        ...(contact
            ? [
                  { title: contact.name, href: `/contacts/${contact.id}` },
                  { title: 'Editar', href: `/contacts/${contact.id}/edit` },
              ]
            : [{ title: 'Nuevo contacto', href: '/contacts/create' }]),
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (contact) {
            put(`/contacts/${contact.id}`);
        } else {
            post('/contacts');
        }
    };

    const text = (field: keyof ContactValues, label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input id={field} value={data[field] as string} onChange={(e) => setData(field, e.target.value)} {...props} />
            <InputError message={errors[field]} />
        </div>
    );

    const suggest = (field: 'profile' | 'language' | 'referral_source', label: string, values: string[]) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input id={field} list={`${field}-options`} value={data[field]} onChange={(e) => setData(field, e.target.value)} />
            <datalist id={`${field}-options`}>
                {values.map((v) => (
                    <option key={v} value={v} />
                ))}
            </datalist>
            <InputError message={errors[field]} />
        </div>
    );

    const toggleProduct = (key: string, on: boolean) =>
        setData('preferred_products', on ? [...data.preferred_products, key] : data.preferred_products.filter((p) => p !== key));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={contact ? `Editar ${contact.name}` : 'Nuevo contacto'} />

            <div className="flex max-w-4xl min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
                <Heading
                    title={contact ? `Editar ${contact.name}` : 'Nuevo contacto'}
                    description={
                        editing
                            ? 'Los cambios quedan en la auditoría.'
                            : 'Para agregar a alguien que no está en los Excel. Si el correo o el documento ya existen, te avisamos para no duplicarlo.'
                    }
                />

                <form onSubmit={submit} className="space-y-8">
                    <section className="grid gap-4 sm:grid-cols-2">
                        <h2 className="text-base font-semibold sm:col-span-2">Datos personales</h2>
                        {text('first_name', 'Nombres', { required: true, autoComplete: 'off' })}
                        {text('last_name', 'Apellidos', { autoComplete: 'off' })}
                        {text('email', 'Correo', { type: 'email', autoComplete: 'off' })}
                        {text('phone', 'Teléfono', { type: 'tel', autoComplete: 'off', placeholder: '3001234567 o +593…' })}
                        <div className="grid gap-2">
                            <Label htmlFor="document_type">Tipo de documento</Label>
                            <select
                                id="document_type"
                                className={selectClass}
                                value={data.document_type}
                                onChange={(e) => setData('document_type', e.target.value)}
                            >
                                {['', 'CC', 'CE', 'TI', 'Pasaporte', 'Otro'].map((t) => (
                                    <option key={t} value={t}>
                                        {t || 'Sin documento'}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {text('document_number', 'Documento', { autoComplete: 'off' })}
                        <div className="grid gap-2">
                            <Label htmlFor="gender">Género</Label>
                            <select id="gender" className={selectClass} value={data.gender} onChange={(e) => setData('gender', e.target.value)}>
                                {['', 'Mujer', 'Hombre', 'Otro'].map((g) => (
                                    <option key={g} value={g}>
                                        {g || 'Sin dato'}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {text('organization', 'Empresa u organización')}
                        {text('country', 'País')}
                        {text('state', 'Departamento o estado')}
                        {text('city', 'Ciudad')}
                        <p className="text-muted-foreground text-xs sm:col-span-2">Necesitamos al menos el correo, el documento o el teléfono.</p>
                    </section>

                    <section className="grid gap-4 sm:grid-cols-2">
                        <h2 className="text-base font-semibold sm:col-span-2">Perfil e intereses</h2>
                        {suggest('profile', 'Perfil', options.profiles)}
                        {suggest('language', 'Idioma', options.languages)}
                        {suggest('referral_source', '¿Cómo nos conoció?', options.referrals)}

                        <fieldset className="grid gap-3 sm:col-span-2">
                            <legend className="mb-2 text-sm font-medium">Producto preferido</legend>
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    checked={data.products_auto}
                                    onCheckedChange={(v) => setData('products_auto', v === true)}
                                    className="mt-0.5"
                                />
                                <span>
                                    Calcularlo con sus compras
                                    <span className="text-muted-foreground block text-xs">
                                        Se actualiza solo con lo que compre en el plugin y lo que traigan los Excel de ferias.
                                    </span>
                                </span>
                            </label>
                            <div className="flex flex-wrap gap-4">
                                {Object.entries(options.products).map(([key, label]) => (
                                    <label key={key} className={`flex items-center gap-2 text-sm ${data.products_auto ? 'opacity-50' : ''}`}>
                                        <Checkbox
                                            checked={data.preferred_products.includes(key)}
                                            disabled={data.products_auto}
                                            onCheckedChange={(v) => toggleProduct(key, v === true)}
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.preferred_products} />
                        </fieldset>
                    </section>

                    <section className="grid gap-4 sm:grid-cols-2">
                        <h2 className="text-base font-semibold sm:col-span-2">Relación con la feria</h2>
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">Tipo</legend>
                            {(
                                [
                                    ['is_attendee', 'Asistente (fue a una feria)'],
                                    ['is_interested', 'Interesado (pidió información)'],
                                    ['is_subscriber', 'Suscriptor del boletín'],
                                ] as const
                            ).map(([field, label]) => (
                                <label key={field} className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={data[field]} onCheckedChange={(v) => setData(field, v === true)} />
                                    {label}
                                </label>
                            ))}
                        </fieldset>

                        {!editing && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="edition_year">Año de la feria</Label>
                                <Input
                                    id="edition_year"
                                    type="number"
                                    min={2000}
                                    max={2100}
                                    value={data.edition_year}
                                    onChange={(e) => setData('edition_year', e.target.value)}
                                    aria-describedby="year-hint"
                                />
                                <p id="year-hint" className="text-muted-foreground text-xs">
                                    Año en que asistió o mostró interés. Déjalo vacío si no aplica.
                                </p>
                                <InputError message={errors.edition_year} />
                            </div>
                        )}

                        <fieldset className="grid gap-2 sm:col-span-2">
                            <legend className="mb-2 text-sm font-medium">Estado</legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(
                                    [
                                        ['activo', 'Activo', 'Recibe las campañas de correo. Ya aceptó la política de privacidad.'],
                                        ['desinscrito', 'Desinscrito', 'No recibe campañas. Úsalo si pidió no recibir más correos.'],
                                    ] as const
                                ).map(([value, title, hint]) => (
                                    <label
                                        key={value}
                                        className="has-[:checked]:border-primary has-[:checked]:bg-primary/5 flex cursor-pointer gap-3 rounded-lg border p-4"
                                    >
                                        <input
                                            type="radio"
                                            name="status"
                                            value={value}
                                            checked={data.status === value}
                                            onChange={() => setData('status', value)}
                                            className="accent-primary mt-1"
                                        />
                                        <span>
                                            <span className="block text-sm font-medium">{title}</span>
                                            <span className="text-muted-foreground block text-xs">{hint}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {contact?.status === 'desinscrito' && data.status === 'activo' && (
                                <p className="text-warning text-xs">
                                    Esta persona se desinscribió. Vuelve a activarla solo si te pidió recibir correos otra vez.
                                </p>
                            )}
                        </fieldset>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="notes">Notas</Label>
                            <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            <InputError message={errors.notes} />
                        </div>
                    </section>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                            {editing ? 'Guardar cambios' : 'Crear contacto'}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={contact ? `/contacts/${contact.id}` : '/contacts'}>Cancelar</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
