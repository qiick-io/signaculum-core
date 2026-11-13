import { Link } from '@inertiajs/react';
import { Mail, Settings, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index as organizationsInvitations } from '@/routes/organizations/invitations';
import {
    show as organizationsShow,
    settings as organizationsSettings,
} from '@/routes/organizations';

export type OrganizationView = 'members' | 'invitations' | 'settings';

/**
 * Organisation view tabs (Members / Invitations / Settings), each with its own URL
 * per app-ui `page.md` (line tabs under the header, Link triggers, no TabsContent).
 */
export function OrganizationViewTabs({
    slug,
    view,
}: {
    slug: string;
    view: OrganizationView;
}) {
    const { t } = useTranslation();

    const views: {
        id: OrganizationView;
        href: string;
        icon: typeof Users;
        label: string;
    }[] = [
        {
            id: 'members',
            href: organizationsShow.url(slug),
            icon: Users,
            label: t('organizations.views.members'),
        },
        {
            id: 'invitations',
            href: organizationsInvitations.url(slug),
            icon: Mail,
            label: t('organizations.views.invitations'),
        },
        {
            id: 'settings',
            href: organizationsSettings.url(slug),
            icon: Settings,
            label: t('organizations.views.settings'),
        },
    ];

    return (
        <Tabs
            value={view}
            activationMode="manual"
            className="overflow-x-auto border-b px-4"
        >
            <TabsList
                variant="line"
                aria-label={t('organizations.views.label')}
            >
                {views.map((entry) => {
                    const Icon = entry.icon;

                    return (
                        <TabsTrigger
                            key={entry.id}
                            value={entry.id}
                            asChild
                            aria-controls={undefined}
                        >
                            <Link href={entry.href} prefetch>
                                <Icon className="size-4" aria-hidden />
                                {entry.label}
                            </Link>
                        </TabsTrigger>
                    );
                })}
            </TabsList>
        </Tabs>
    );
}
