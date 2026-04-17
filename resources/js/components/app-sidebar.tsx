import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { ArrowRightLeft, Camera, FileText, Filter, Globe, LayoutGrid, Settings, Users } from 'lucide-react';
import AppLogo from './app-logo';
import { PropertySwitcher } from './property-switcher';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Traffic',
        url: '/reports/traffic',
        icon: ArrowRightLeft,
    },
    {
        title: 'Content',
        url: '/reports/content',
        icon: FileText,
    },
    {
        title: 'Audience',
        url: '/reports/audience',
        icon: Users,
    },
    {
        title: 'Funnels',
        url: '/funnels',
        icon: Filter,
    },
    {
        title: 'Snapshots',
        url: '/snapshots',
        icon: Camera,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Properties',
        url: '/properties',
        icon: Globe,
    },
    {
        title: 'Settings',
        url: '/settings/profile',
        icon: Settings,
    },
];

export function AppSidebar() {
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
                <div className="px-3 py-2">
                    <PropertySwitcher />
                </div>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
