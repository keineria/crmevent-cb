import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Award, CalendarDays, ChartColumn, LayoutGrid, Mail, MessageSquareHeart, PiggyBank, ScanLine, Ticket, Users } from 'lucide-react';
import AppLogo from './app-logo';

// Keep in sync with Roles::CONTACT_VIEWERS, Roles::REGISTRATION_VIEWERS, Roles::CHECKIN_STAFF, Roles::CAMPAIGN_VIEWERS,
// Roles::REPORT_VIEWERS, Roles::FINANCE_VIEWERS, Roles::CERTIFICATE_MANAGERS, Roles::SURVEY_MANAGERS and Roles::SURVEY_VIEWERS.
const CONTACT_VIEWERS = ['Admin', 'Admin registro', 'Analista', 'Marketing'];
const REGISTRATION_VIEWERS = ['Admin', 'Admin registro', 'Registro', 'Analista'];
const CHECKIN_STAFF = ['Admin', 'Admin registro', 'Registro', 'Registro salidas'];
const CAMPAIGN_VIEWERS = ['Admin', 'Marketing', 'Analista'];
const REPORT_VIEWERS = ['Admin', 'Admin registro', 'Analista'];
const FINANCE_VIEWERS = ['Admin', 'Analista'];
const CERTIFICATE_MANAGERS = ['Admin', 'Admin registro', 'Marketing'];
const SURVEY_MANAGERS = ['Admin', 'Admin registro', 'Marketing'];
const SURVEY_VIEWERS = ['Admin', 'Admin registro', 'Analista', 'Marketing'];

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
        ...(auth.roles.some((r) => CERTIFICATE_MANAGERS.includes(r)) ? [{ title: 'Certificados', url: '/certificados', icon: Award }] : []),
        // Managers land on the questions and sending; people who only read results, on the results.
        ...(auth.roles.some((r) => SURVEY_VIEWERS.includes(r))
            ? [
                  {
                      title: 'Encuestas',
                      url: auth.roles.some((r) => SURVEY_MANAGERS.includes(r)) ? '/encuestas' : '/reports/encuestas',
                      icon: MessageSquareHeart,
                  },
              ]
            : []),
        ...(auth.roles.some((r) => REPORT_VIEWERS.includes(r)) ? [{ title: 'Reportes', url: '/reports', icon: ChartColumn }] : []),
        ...(auth.roles.some((r) => FINANCE_VIEWERS.includes(r)) ? [{ title: 'Finanzas', url: '/finance', icon: PiggyBank }] : []),
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
