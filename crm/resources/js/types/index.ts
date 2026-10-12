import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    roles: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

/** Logo URLs from Configuración › Marca; login and email fall back to the platform logo. */
export interface Branding {
    platform: string | null;
    login: string | null;
    email: string | null;
}

export interface SharedData {
    name: string;
    auth: Auth;
    branding?: Branding;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
