import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, LayoutGrid, Mail, ScanLine, Ticket, Users } from 'lucide-react';
import AppLogo from './app-logo';

// Keep in sync with Roles::CONTACT_VIEWERS, Roles::REGISTRATION_VIEWERS, Roles::CHECKIN_STAFF and Roles::CAMPAIGN_VIEWERS.
const CONTACT_VIEWERS = ['Admin', 'Admin registro', 'Analista', 'Marketing'];
const REGISTRATION_VIEWERS = ['Admin', 'Admin registro', 'Registro', 'Analista'];
const CHECKIN_STAFF = ['Admin', 'Admin registro', 'Registro', 'Registro salidas'];
const CAMPAIGN_VIEWERS = ['Admin', 'Marketing', 'Analista'];

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
        ...(auth.roles.some((r) => CAMPAIGN_VIEWERS.includes(r)) ? [{ title: 'Campañas', url: '/campaigns', icon: Mail }] : []),
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
