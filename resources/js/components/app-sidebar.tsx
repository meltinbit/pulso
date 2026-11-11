import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Camera, Coins, Globe, HeartPulse, KeyRound, NotebookPen, ScrollText, Settings } from 'lucide-react';
import AppLogo from './app-logo';
import { PropertyAwareLink } from './property-aware-link';
import { PropertySwitcher } from './property-switcher';

const mainNavItems: NavItem[] = [
    {
        title: 'Health',
        url: '/dashboard',
        icon: HeartPulse,
    },
    {
        title: 'MCP log',
        url: '/mcp-log',
        icon: ScrollText,
    },
    {
        title: 'Annotations',
        url: '/annotations',
        icon: NotebookPen,
    },
    {
        title: 'Snapshots',
        url: '/snapshots',
        icon: Camera,
    },
    {
        title: 'AdSense',
        url: '/adsense',
        icon: Coins,
    },
    {
        title: 'Keywords',
        url: '/keywords',
        icon: KeyRound,
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
                            <PropertyAwareLink href="/dashboard" prefetch>
                                <AppLogo />
                            </PropertyAwareLink>
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
