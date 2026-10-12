import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import ThemePicker from '@/components/theme-picker';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Apariencia',
        href: '/settings/appearance',
    },
];

export default function Appearance() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Apariencia" />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall title="Apariencia" description="Colores y modo claro u oscuro de la plataforma." />
                        <AppearanceTabs />
                    </div>
                    <ThemePicker />
                    <p className="text-muted-foreground text-sm">Se guarda en tu cuenta: te sigue en el móvil, la tablet y el ordenador.</p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
