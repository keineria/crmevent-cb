import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import AppLogoIcon from './app-logo-icon';

/**
 * Logo of the login, password and portal pages: the login logo from Configuración › Marca,
 * else the platform logo, else the bird icon.
 */
export default function LoginLogo({ className = 'size-9', imageClassName = 'h-24 max-w-[320px]' }: { className?: string; imageClassName?: string }) {
    const { branding } = usePage<SharedData>().props;

    if (branding?.login) {
        return <img src={branding.login} alt="Colombia Birdfair" className={`${imageClassName} object-contain`} />;
    }

    return <AppLogoIcon className={`text-primary ${className}`} />;
}
