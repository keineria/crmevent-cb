import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, LayoutGrid, ScanLine, Ticket, Users } from 'lucide-react';
import AppLogo from './app-logo';

// Keep in sync with Roles::CONTACT_VIEWERS, Roles::REGISTRATION_VIEWERS and Roles::CHECKIN_STAFF.
const CONTACT_VIEWERS = ['Admin', 'Admin registro', 'Analista', 'Marketing'];
const REGISTRATION_VIEWERS = ['Admin', 'Admin registro', 'Registro', 'Analista'];
const CHECKIN_STAFF = ['Admin', 'Admin registro', 'Registro', 'Registro salidas'];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const mainNavItems: NavItem[] = [
        { title: 'Resumen', url: '/dashboard', icon: LayoutGrid },
        ...(auth.roles.some((r) => CHECKIN_STAFF.includes(r)) ? [{ title: 'Check-in', url: '/checkin', icon: ScanLine }] : []),
        ...(auth.roles.some((r) => REGISTRATION_VIEWERS.includes(r))
            ? [
                  { title: 'Inscripciones', url: '/registrations', icon: Ticket },
                  { title: 'Actividades', url: '/activities', icon: CalendarDays },
              ]
            : []),
        ...(auth.roles.some((r) => CONTACT_VIEWERS.includes(r)) ? [{ title: 'Contactos', url: '/contacts', icon: Users }] : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
