import { Head, router, usePage } from '@inertiajs/react';
import { AppWindow, Unplug } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import Heading from '@/components/heading';
import { TablePanel } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatRelativeTime } from '@/lib/relative-time';
import {
    destroy,
    index as connectedApplicationsIndex,
} from '@/routes/connected-applications';
import type { BreadcrumbItem, ConnectedApplication } from '@/types';

/**
 * Settings → Connected apps: applications that can sign this user in without asking,
 * with "Remove access". Same table pattern as Sessions (app-ui `list-page.md`).
 */
export default function ConnectedApplications({
    applications,
}: {
    applications: ConnectedApplication[];
}) {
    const { t } = useTranslation();
    const { locale } = usePage().props;
    const [applicationToRevoke, setApplicationToRevoke] =
        useState<ConnectedApplication | null>(null);
    const [isConfirming, setIsConfirming] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.connectedApplications.breadcrumb'),
            href: connectedApplicationsIndex(),
        },
    ];

    const confirmRevoke = () => {
        if (!applicationToRevoke) {
            return;
        }

        setIsConfirming(true);
        router.visit(destroy(applicationToRevoke.clientId), {
            preserveScroll: true,
            onFinish: () => {
                setIsConfirming(false);
                setApplicationToRevoke(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.connectedApplications.head')} />

            <h1 className="sr-only">
                {t('settings.connectedApplications.head')}
            </h1>

            <SettingsLayout wide>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('settings.connectedApplications.title')}
                        description={t(
                            'settings.connectedApplications.description',
                        )}
                    />

                    {applications.length === 0 ? (
                        <PageEmptyState
                            compact
                            icon={AppWindow}
                            title={t(
                                'settings.connectedApplications.emptyTitle',
                            )}
                            description={t(
                                'settings.connectedApplications.emptyDescription',
                            )}
                        />
                    ) : (
                        <TablePanel>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'settings.connectedApplications.columns.application',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'settings.connectedApplications.columns.access',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'settings.connectedApplications.columns.lastSignIn',
                                            )}
                                        </TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                {t('common.actions')}
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {applications.map((application) => (
                                        <TableRow key={application.clientId}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <AppWindow
                                                        className="size-4 text-muted-foreground"
                                                        aria-hidden
                                                    />
                                                    <span className="font-medium">
                                                        {application.name}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {application.scopes.map(
                                                        (scope) => (
                                                            <Badge
                                                                key={scope}
                                                                variant="outline"
                                                            >
                                                                {scope}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {formatRelativeTime(
                                                    application.lastAuthorizedAt,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={t(
                                                                'settings.connectedApplications.removeAccess',
                                                            )}
                                                            onClick={() =>
                                                                setApplicationToRevoke(
                                                                    application,
                                                                )
                                                            }
                                                        >
                                                            <Unplug />
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        {t(
                                                            'settings.connectedApplications.removeAccess',
                                                        )}
                                                    </TooltipContent>
                                                </Tooltip>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </TablePanel>
                    )}
                </div>

                <ConfirmDestructiveDialog
                    open={applicationToRevoke !== null}
                    onOpenChange={(isOpen) => {
                        if (!isOpen) {
                            setApplicationToRevoke(null);
                        }
                    }}
                    title={t('settings.connectedApplications.confirm.title', {
                        name: applicationToRevoke?.name ?? '',
                    })}
                    description={t(
                        'settings.connectedApplications.confirm.description',
                    )}
                    confirmLabel={t(
                        'settings.connectedApplications.removeAccess',
                    )}
                    confirming={isConfirming}
                    onConfirm={confirmRevoke}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
