import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { dateTime } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleCheck, CircleX, History, LoaderCircle, Pencil, Power, PowerOff, Trash2, UserPlus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface StaffUser {
    id: number;
    name: string;
    email: string;
    roles: string[];
    is_me: boolean;
    active: boolean;
    last_login_at: string | null;
}

interface PageProps {
    users: StaffUser[];
    roles: string[];
    roleHints: Record<string, string>;
    flash: { success?: string; error?: string };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Usuarios', href: '/settings/users' }];

function UserForm({ user, onDone }: { user?: StaffUser; onDone: () => void }) {
    const { roles, roleHints } = usePage<PageProps>().props;
    const { data, setData, post, put, processing, errors } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        roles: user?.roles ?? ([] as string[]),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (user) put(`/settings/users/${user.id}`, options);
        else post('/settings/users', options);
    };

    const toggle = (role: string, on: boolean) => setData('roles', on ? [...data.roles, role] : data.roles.filter((r) => r !== role));

    return (
        <form onSubmit={submit} className="bg-muted/30 space-y-4 rounded-xl border p-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="user-name">Nombre</Label>
                    <Input id="user-name" required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="user-email">Correo</Label>
                    <Input
                        id="user-email"
                        type="email"
                        required
                        disabled={!!user}
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>
            </div>
            <div className="grid gap-2">
                <Label htmlFor="user-password">{user ? 'Nueva contraseña (opcional)' : 'Contraseña'}</Label>
                <Input
                    id="user-password"
                    type="password"
                    autoComplete="new-password"
                    required={!user}
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                />
                <p className="text-muted-foreground text-xs">Mínimo 10 caracteres, con letras y números. Entrégala a la persona en privado.</p>
                <InputError message={errors.password} />
            </div>
            <fieldset className="space-y-2">
                <legend className="mb-2 text-sm font-medium">Roles</legend>
                {roles.map((role) => (
                    <label key={role} className="flex items-start gap-3 text-sm">
                        <Checkbox className="mt-0.5" checked={data.roles.includes(role)} onCheckedChange={(v) => toggle(role, v === true)} />
                        <span>
                            <span className="font-medium">{role}</span>
                            <span className="text-muted-foreground block text-xs">{roleHints[role]}</span>
                        </span>
                    </label>
                ))}
                <InputError message={errors.roles} />
            </fieldset>
            <div className="flex gap-2">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                    {user ? 'Guardar' : 'Crear usuario'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancelar
                </Button>
            </div>
        </form>
    );
}

export default function Users() {
    const { users, flash } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<number | 'new' | null>(null);

    const remove = (user: StaffUser) => {
        if (confirm(`¿Borrar el usuario ${user.email}? Ya no podrá entrar al CRM. Si solo quieres quitarle el acceso, mejor desactívalo.`)) {
            router.delete(`/settings/users/${user.id}`, { preserveScroll: true });
        }
    };

    const toggleActive = (user: StaffUser) => {
        if (
            user.active &&
            !confirm(`¿Desactivar a ${user.email}? Saldrá del CRM en su próximo clic y no podrá volver a entrar hasta que lo actives.`)
        ) {
            return;
        }
        router.post(`/settings/users/${user.id}/${user.active ? 'deactivate' : 'activate'}`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuarios" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Usuarios"
                        description="Cuentas del equipo. Para el check-in, crea a cada persona de la mesa con el rol Registro, o Registro salidas si solo recibe gente en las salidas. Todo lo que hace cada usuario queda en Auditoría."
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

                    {editing === 'new' ? (
                        <UserForm onDone={() => setEditing(null)} />
                    ) : (
                        <Button onClick={() => setEditing('new')}>
                            <UserPlus aria-hidden />
                            Nuevo usuario
                        </Button>
                    )}

                    <ul className="divide-y rounded-xl border">
                        {users.map((user) =>
                            editing === user.id ? (
                                <li key={user.id} className="p-2">
                                    <UserForm user={user} onDone={() => setEditing(null)} />
                                </li>
                            ) : (
                                <li key={user.id} className={`flex flex-wrap items-center gap-3 px-4 py-3 ${user.active ? '' : 'bg-muted/40'}`}>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {user.name}
                                            {user.is_me && <span className="text-muted-foreground font-normal"> (tú)</span>}
                                            {!user.active && (
                                                <Badge variant="outline" className="text-destructive ml-2">
                                                    Inactivo
                                                </Badge>
                                            )}
                                        </p>
                                        <p className="text-muted-foreground truncate text-xs">{user.email}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {user.last_login_at
                                                ? `Último acceso: ${dateTime.format(new Date(user.last_login_at))}`
                                                : 'Aún no ha entrado'}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {user.roles.map((r) => (
                                            <Badge key={r} variant="secondary">
                                                {r}
                                            </Badge>
                                        ))}
                                    </div>
                                    <div className="flex gap-1">
                                        <Button size="icon" variant="ghost" asChild>
                                            <Link
                                                href={`/settings/audit?user=${user.id}`}
                                                aria-label={`Ver actividad de ${user.name}`}
                                                title="Ver actividad"
                                            >
                                                <History aria-hidden />
                                            </Link>
                                        </Button>
                                        <Button size="icon" variant="ghost" aria-label={`Editar ${user.name}`} onClick={() => setEditing(user.id)}>
                                            <Pencil aria-hidden />
                                        </Button>
                                        {!user.is_me && (
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={user.active ? `Desactivar ${user.name}` : `Activar ${user.name}`}
                                                title={user.active ? 'Desactivar' : 'Activar'}
                                                onClick={() => toggleActive(user)}
                                            >
                                                {user.active ? <PowerOff aria-hidden /> : <Power aria-hidden />}
                                            </Button>
                                        )}
                                        {!user.is_me && (
                                            <Button size="icon" variant="ghost" aria-label={`Borrar ${user.name}`} onClick={() => remove(user)}>
                                                <Trash2 aria-hidden />
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ),
                        )}
                    </ul>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
