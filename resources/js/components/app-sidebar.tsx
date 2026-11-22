import { Link } from '@inertiajs/react';
import {
    AppWindow,
    BookOpen,
    Building2,
    FolderGit2,
    House,
    KeyRound,
    ScrollText,
    Settings,
    Users,
    UsersRound,
} from 'lucide-react';
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
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as adminActivityLogsIndex } from '@/routes/admin/activity-logs';
import { index as adminApiKeysIndex } from '@/routes/admin/api-keys';
import { index as adminApplicationsIndex } from '@/routes/admin/applications';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { index as adminGroupsIndex } from '@/routes/admin/groups';
import { index as organizationsIndex } from '@/routes/organizations';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

/**
 * Primary application sidebar (app-ui `shell.md`): logo, the Platform group, an
 * Admin group gated by effective permissions, then the footer links and the user menu.
 */
export function AppSidebar() {
    const { t } = useTranslation();
    const { can } = useCan();

    const platformNavItems: MainNavItem[] = [
        {
            title: t('nav.home'),
            href: dashboard(),
            icon: House,
        },
        {
            title: t('nav.organizations'),
            href: organizationsIndex(),
            icon: Building2,
            activePrefix: '/organizations',
        },
        {
            title: t('nav.settings'),
            href: editProfile(),
            icon: Settings,
            activePrefix: '/settings',
        },
    ];

    const adminNavItems: MainNavItem[] = [
        ...(can(PermissionEnum.CanShowUsers)
            ? [
                  {
                      title: t('nav.users'),
                      href: adminUsersIndex(),
                      icon: Users,
                      activePrefix: '/admin/users',
                  } satisfies MainNavItem,
              ]
            : []),
        ...(can(PermissionEnum.CanShowGroups)
            ? [
                  {
                      title: t('nav.groups'),
                      href: adminGroupsIndex(),
                      icon: UsersRound,
                      activePrefix: '/admin/groups',
                  } satisfies MainNavItem,
              ]
            : []),
        ...(can(PermissionEnum.CanShowApplications)
            ? [
                  {
                      title: t('nav.applications'),
                      href: adminApplicationsIndex(),
                      icon: AppWindow,
                      activePrefix: '/admin/applications',
                  } satisfies MainNavItem,
              ]
            : []),
        ...(can(PermissionEnum.CanShowActivityLogs)
            ? [
                  {
                      title: t('nav.activityLog'),
                      href: adminActivityLogsIndex(),
                      icon: ScrollText,
                      activePrefix: '/admin/activity-logs',
                  } satisfies MainNavItem,
              ]
            : []),
        ...(can(PermissionEnum.CanShowApiKeys)
            ? [
                  {
                      title: t('nav.apiKeys'),
                      href: adminApiKeysIndex(),
                      icon: KeyRound,
                      activePrefix: '/admin/api-keys',
                  } satisfies MainNavItem,
              ]
            : []),
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
                    <NavMain items={adminNavItems} label={t('nav.admin')} />
                </SidebarContent>

                <SidebarFooter>
                    <NavFooter items={footerNavItems} className="mt-auto" />
                    <AppNavUser />
                </SidebarFooter>
            </nav>
        </Sidebar>
    );
}
