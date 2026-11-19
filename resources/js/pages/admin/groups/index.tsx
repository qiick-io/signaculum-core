import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type GroupRow = {
    id: number;
    name: string;
    description: string | null;
    users_count: number;
    roles_count: number;
};

type Props = {
    groups: {
        data: GroupRow[];
    };
    filters: {
        search: string;
        trashed: boolean;
    };
};

/**
 * Instance user groups list (externa-core shape). Create/edit drawer lands in a follow-up;
 * membership and roles are managed through the API routes already covered by tests.
 */
export default function AdminGroupsIndex({ groups, filters }: Props) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.groups'), href: '/admin/groups' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.groups')} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title={t('nav.groups')}
                    description={t('admin.groups.description', {
                        defaultValue:
                            'Groups grant roles to their members. Effective permissions are the union of direct and group roles.',
                    })}
                />
                <div className="flex items-center gap-2">
                    <Button
                        variant={filters.trashed ? 'secondary' : 'outline'}
                        size="sm"
                        onClick={() =>
                            router.get('/admin/groups', {
                                trashed: filters.trashed ? 0 : 1,
                            })
                        }
                    >
                        {filters.trashed
                            ? t('admin.groups.showActive', {
                                  defaultValue: 'Show active',
                              })
                            : t('admin.groups.showTrash', {
                                  defaultValue: 'Show trash',
                              })}
                    </Button>
                </div>
                <ul className="divide-y rounded-md border">
                    {groups.data.map((group) => (
                        <li
                            key={group.id}
                            className="flex items-center justify-between gap-4 px-4 py-3"
                        >
                            <div>
                                <div className="font-medium">{group.name}</div>
                                {group.description ? (
                                    <div className="text-muted-foreground text-sm">
                                        {group.description}
                                    </div>
                                ) : null}
                            </div>
                            <div className="text-muted-foreground text-sm">
                                {group.users_count} / {group.roles_count}
                            </div>
                        </li>
                    ))}
                    {groups.data.length === 0 ? (
                        <li className="text-muted-foreground px-4 py-8 text-center text-sm">
                            {t('admin.groups.empty', {
                                defaultValue: 'No groups yet.',
                            })}
                        </li>
                    ) : null}
                </ul>
            </div>
        </AppLayout>
    );
}
