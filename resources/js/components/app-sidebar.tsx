import { Link } from '@inertiajs/react';
import { BookOpen, FolderGit2, House, Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import { AppNavUser } from '@/components/app-user-menu';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import type { MainNavItem } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

/**
 * Primary application sidebar (app-ui `shell.md`): logo, the Platform group, then the
 * footer links and the user menu. The Admin group joins with the admin screens.
 */
export function AppSidebar() {
    const { t } = useTranslation();

    const platformNavItems: MainNavItem[] = [
        {
            title: t('nav.home'),
            href: dashboard(),
            icon: House,
        },
        {
            title: t('nav.settings'),
            href: editProfile(),
            icon: Settings,
            activePrefix: '/settings',
        },
    ];

    const footerNavItems: NavItem[] = [
        {
            title: t('nav.repository'),
            href: 'https://github.com/qiick-io/signaculum-core',
            icon: FolderGit2,
        },
        {
            title: t('nav.documentation'),
            href: 'https://github.com/qiick-io/signaculum-core#readme',
            icon: BookOpen,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            {/*
              Real <nav> (not role=navigation on a div) so desktop chrome and the
              mobile Sheet both expose a labelled navigation landmark.
            */}
            <nav
                aria-label={t('a11y.mainNav')}
                className="flex h-full w-full flex-col"
            >
                <SidebarHeader>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href={dashboard()} prefetch>
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarHeader>

                <SidebarContent>
                    <NavMain items={platformNavItems} />
                </SidebarContent>

                <SidebarFooter>
                    <NavFooter items={footerNavItems} className="mt-auto" />
                    <AppNavUser />
                </SidebarFooter>
            </nav>
        </Sidebar>
    );
}
