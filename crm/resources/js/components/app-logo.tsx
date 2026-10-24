import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import AppLogoIcon from './app-logo-icon';

export default function AppLogo({ imageClassName = 'h-8 max-w-[180px]' }: { imageClassName?: string }) {
    const { branding } = usePage<SharedData>().props;

    // A logo uploaded in Configuración › Marca replaces the icon and name.
    if (branding?.platform) {
        return <img src={branding.platform} alt="CRM 360 · Birdfair" className={`${imageClassName} object-contain object-left`} />;
    }

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <AppLogoIcon className="text-sidebar-primary-foreground size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">CRM 360 · Birdfair</span>
            </div>
        </>
    );
}
