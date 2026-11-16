import type { ColumnDef } from '@tanstack/react-table';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowDownAZ,
    ArrowUpAZ,
    KeyRound,
    LockOpen,
    Pencil,
    SearchX,
    Shield,
    ShieldOff,
    UserCheck,
    UserPlus,
    UserRoundSearch,
    UserX,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    ActionsOverflow,
    type OverflowActionDefinition,
} from '@/components/admin/actions-overflow';
import { DataTable } from '@/components/admin/data-table';
import type { DataTableSort } from '@/components/admin/data-table';
import { DataTableColumnHeader } from '@/components/admin/data-table-column-header';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { UserAvatar } from '@/components/admin/user-avatar';
import { UserFormDrawer } from '@/components/admin/user-form-drawer';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Drawer } from '@/components/ui/drawer';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import AppLayout from '@/layouts/app-layout';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { update as updateActivation } from '@/routes/admin/users/activation';
import { store as storeImpersonation } from '@/routes/admin/users/impersonation';
import { update as updateInstanceAdmin } from '@/routes/admin/users/instance-admin';
import { destroy as destroyLockout } from '@/routes/admin/users/lockout';
import { destroy as destroyTwoFactor } from '@/routes/admin/users/two-factor';
import { index as usersIndex } from '@/routes/admin/users';
import type { AdminUserRow, BreadcrumbItem, Paginated } from '@/types';

type PendingUserAction =
    | { type: 'deactivate'; user: AdminUserRow }
    | { type: 'resetTwoFactor'; user: AdminUserRow }
    | { type: 'revokeInstanceAdmin'; user: AdminUserRow }
    | null;

type UserSortField = 'name' | 'email' | 'created_at' | 'updated_at';
type UserSortDirection = 'asc' | 'desc';
type UserStatusFilter = 'active' | 'deactivated';

const USER_SORT_FIELDS: UserSortField[] = [
    'name',
    'email',
    'created_at',
    'updated_at',
];

function isUserSortField(value: string): value is UserSortField {
    return (USER_SORT_FIELDS as string[]).includes(value);
}

type Filters = {
    search?: string;
    status?: UserStatusFilter;
    sort?: UserSortField;
    direction?: UserSortDirection;
};

/**
 * Admin → Users: list with search, active/deactivated filter, sort, pagination
 * and a create/edit drawer. Pattern from app-ui `list-page.md` (externa-core
 * `pages/admin/users/index.tsx`).
 */
export default function AdminUsersIndex({
    users: usersProp,
    filters = {},
}: {
    users: LaravelPaginated<AdminUserRow> | Paginated<AdminUserRow>;
    filters?: Filters;
}) {
    const users = normalizePaginated(usersProp);
    const { t } = useTranslation();
    const { canManageUsers } = usePage().props.auth;

    const userSortFields: { value: UserSortField; label: string }[] = [
        { value: 'name', label: t('common.name') },
        { value: 'email', label: t('common.email') },
        { value: 'created_at', label: t('admin.users.columns.created') },
        { value: 'updated_at', label: t('admin.users.columns.updated') },
    ];

    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState<UserStatusFilter>(
        filters.status ?? 'active',
    );
    const [sort, setSort] = useState<UserSortField>(
        filters.sort ?? 'created_at',
    );
    const [direction, setDirection] = useState<UserSortDirection>(
        filters.direction ?? 'desc',
    );
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<AdminUserRow | null>(null);
    const [pendingAction, setPendingAction] = useState<PendingUserAction>(null);
    const [isConfirming, setIsConfirming] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [{ title: t('admin.users.title'), href: usersIndex.url() }],
        [t],
    );

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextStatus = overrides.status ?? status;
            const nextSort = overrides.sort ?? sort;
            const nextDirection = overrides.direction ?? direction;

            router.get(
                usersIndex.url({
                    query: {
                        search: nextSearch || undefined,
                        status: nextStatus,
                        sort: nextSort,
                        direction: nextDirection,
                        per_page: listPerPageQuery(users.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [direction, users.per_page, search, sort, status],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
        setStatus(filters.status ?? 'active');
        setSort(filters.sort ?? 'created_at');
        setDirection(filters.direction ?? 'desc');
    }, [filters.search, filters.status, filters.sort, filters.direction]);

    const onDebouncedSearch = useCallback(
        (value: string): void => {
            visit({ search: value || undefined });
        },
        [visit],
    );

    useDebouncedServerSearch(search, filters.search ?? '', onDebouncedSearch);

    const openCreate = (): void => {
        setEditing(null);
        setDrawerOpen(true);
    };

    const openEdit = (user: AdminUserRow): void => {
        if (!user.can_update) {
            return;
        }

        setEditing(user);
        setDrawerOpen(true);
    };

    const handleDrawerOpenChange = (open: boolean): void => {
        if (open) {
            setDrawerOpen(true);

            return;
        }

        setDrawerOpen(false);
        setEditing(null);
    };

    const hasActiveFilters = search.trim() !== '' || status !== 'active';
    const isListEmpty = users.data.length === 0;
    const hideListChrome = isListEmpty && !hasActiveFilters;

    const changeSort = (nextSort: DataTableSort): void => {
        if (!isUserSortField(nextSort.id)) {
            return;
        }

        setSort(nextSort.id);
        setDirection(nextSort.direction);
        visit({ sort: nextSort.id, direction: nextSort.direction });
    };

    const rowActionsFor = useCallback(
        (user: AdminUserRow): OverflowActionDefinition[] => {
            if (!user.can_update) {
                return [];
            }

            const actions: OverflowActionDefinition[] = [
                user.is_active
                    ? {
                          key: 'deactivate',
                          label: t('admin.users.actions.deactivate'),
                          icon: UserX,
                          destructive: true,
                      }
                    : {
                          key: 'reactivate',
                          label: t('admin.users.actions.reactivate'),
                          icon: UserCheck,
                      },
            ];

            if (user.has_two_factor) {
                actions.push({
                    key: 'resetTwoFactor',
                    label: t('admin.users.actions.resetTwoFactor'),
                    icon: KeyRound,
                    destructive: true,
                });
            }

            if (user.is_locked) {
                actions.push({
                    key: 'unlock',
                    label: t('admin.users.actions.unlock'),
                    icon: LockOpen,
                });
            }

            actions.push(
                user.is_instance_admin
                    ? {
                          key: 'revokeInstanceAdmin',
                          label: t('admin.users.actions.revokeInstanceAdmin'),
                          icon: ShieldOff,
                          destructive: true,
                      }
                    : {
                          key: 'grantInstanceAdmin',
                          label: t('admin.users.actions.grantInstanceAdmin'),
                          icon: Shield,
                      },
            );

            if (user.can_impersonate) {
                actions.push({
                    key: 'impersonate',
                    label: t('admin.users.actions.impersonate'),
                    icon: UserRoundSearch,
                });
            }

            return actions;
        },
        [t],
    );

    const handleRowAction = useCallback(
        (user: AdminUserRow, key: string): void => {
            if (key === 'deactivate') {
                setPendingAction({ type: 'deactivate', user });

                return;
            }

            if (key === 'reactivate') {
                router.put(
                    updateActivation.url(user.id),
                    { is_active: true },
                    { preserveScroll: true },
                );

                return;
            }

            if (key === 'resetTwoFactor') {
                setPendingAction({ type: 'resetTwoFactor', user });

                return;
            }

            if (key === 'unlock') {
                router.delete(destroyLockout.url(user.id), {
                    preserveScroll: true,
                });

                return;
            }

            if (key === 'grantInstanceAdmin') {
                router.put(
                    updateInstanceAdmin.url(user.id),
                    { is_instance_admin: true },
                    { preserveScroll: true },
                );

                return;
            }

            if (key === 'revokeInstanceAdmin') {
                setPendingAction({ type: 'revokeInstanceAdmin', user });

                return;
            }

            if (key === 'impersonate') {
                router.post(storeImpersonation.url(user.id));
            }
        },
        [],
    );

    const confirmPendingAction = (): void => {
        if (pendingAction === null) {
            return;
        }

        setIsConfirming(true);

        const finish = {
            preserveScroll: true,
            onFinish: () => {
                setIsConfirming(false);
                setPendingAction(null);
            },
        };

        if (pendingAction.type === 'deactivate') {
            router.put(
                updateActivation.url(pendingAction.user.id),
                { is_active: false },
                finish,
            );

            return;
        }

        if (pendingAction.type === 'resetTwoFactor') {
            router.delete(destroyTwoFactor.url(pendingAction.user.id), finish);

            return;
        }

        router.put(
            updateInstanceAdmin.url(pendingAction.user.id),
            { is_instance_admin: false },
            finish,
        );
    };

    const columns: ColumnDef<AdminUserRow>[] = [
        {
            accessorKey: 'name',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('admin.users.columns.name')}
                />
            ),
            cell: ({ row }) => (
                <span className="inline-flex min-w-0 items-center gap-2">
                    <UserAvatar
                        userId={row.original.id}
                        name={row.original.name}
                        email={row.original.email}
                        size="sm"
                    />
                    <TruncatedText text={row.original.name} />
                </span>
            ),
        },
        {
            accessorKey: 'email',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[12rem] text-muted-foreground' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('admin.users.columns.email')}
                />
            ),
            cell: ({ row }) => <TruncatedText text={row.original.email} />,
        },
        {
            id: 'status',
            header: t('admin.users.columns.status'),
            cell: ({ row }) =>
                row.original.is_active ? (
                    <Badge variant="success" className="gap-1.5">
                        <span
                            className="size-1.5 rounded-full bg-success"
                            aria-hidden
                        />
                        {t('admin.users.status.active')}
                    </Badge>
                ) : (
                    <Badge variant="muted" className="gap-1.5">
                        <span
                            className="size-1.5 rounded-full bg-muted-foreground"
                            aria-hidden
                        />
                        {t('admin.users.status.inactive')}
                    </Badge>
                ),
        },
        {
            id: 'actions',
            header: t('admin.users.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) =>
                row.original.can_update ? (
                    <ButtonGroup
                        aria-label={t('admin.users.columns.actions')}
                        className="ml-auto"
                    >
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            label={t('admin.users.actions.edit')}
                            onClick={() => openEdit(row.original)}
                        >
                            <Pencil className="size-3.5" />
                        </HeaderIconButton>
                        <ActionsOverflow
                            iconOnly
                            actions={rowActionsFor(row.original)}
                            onAction={(key) =>
                                handleRowAction(row.original, key)
                            }
                        />
                    </ButtonGroup>
                ) : null,
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                canManageUsers ? (
                    <Button type="button" onClick={openCreate}>
                        <UserPlus className="mr-1 size-4" />
                        {t('admin.users.newUser')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('admin.users.head')} />
            <h1 className="sr-only">{t('admin.users.head')}</h1>

            <Drawer
                direction="right"
                open={drawerOpen}
                onOpenChange={handleDrawerOpenChange}
            >
                <PageLayout
                    filters={
                        hideListChrome ? undefined : (
                            <DataTableToolbar
                                search={search}
                                onSearchChange={setSearch}
                                searchPlaceholder={t(
                                    'admin.users.searchPlaceholder',
                                )}
                            />
                        )
                    }
                    filtersRight={
                        hideListChrome ? null : (
                            <div className="flex items-center gap-1.5">
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    value={status}
                                    onValueChange={(value) => {
                                        if (
                                            value === 'active' ||
                                            value === 'deactivated'
                                        ) {
                                            setStatus(value);
                                            visit({ status: value });
                                        }
                                    }}
                                >
                                    <ToggleGroupItem value="active">
                                        {t('admin.users.status.active')}
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="deactivated">
                                        {t('admin.users.status.inactive')}
                                    </ToggleGroupItem>
                                </ToggleGroup>
                                <Select
                                    value={sort}
                                    onValueChange={(value) => {
                                        if (isUserSortField(value)) {
                                            setSort(value);
                                            visit({ sort: value });
                                        }
                                    }}
                                >
                                    <SelectTrigger
                                        aria-label={t('admin.users.sortBy')}
                                        className="w-[7.5rem]"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'admin.users.sortBy',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        {userSortFields.map((field) => (
                                            <SelectItem
                                                key={field.value}
                                                value={field.value}
                                            >
                                                {field.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label={
                                        direction === 'asc'
                                            ? t('common.sortAscending')
                                            : t('common.sortDescending')
                                    }
                                    onClick={() => {
                                        const nextDirection =
                                            direction === 'asc'
                                                ? 'desc'
                                                : 'asc';
                                        setDirection(nextDirection);
                                        visit({ direction: nextDirection });
                                    }}
                                >
                                    {direction === 'asc' ? (
                                        <ArrowUpAZ className="size-4" />
                                    ) : (
                                        <ArrowDownAZ className="size-4" />
                                    )}
                                </Button>
                            </div>
                        )
                    }
                >
                    {isListEmpty ? (
                        <PageEmptyState
                            icon={hasActiveFilters ? SearchX : Users}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('admin.users.empty.filteredTitle')
                                        : t('admin.users.empty.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t('admin.users.empty.filteredDescription')
                                    : t('admin.users.empty.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            setStatus('active');
                                            visit({
                                                search: undefined,
                                                status: 'active',
                                            });
                                        }}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : canManageUsers ? (
                                    <Button type="button" onClick={openCreate}>
                                        <UserPlus className="size-4" />
                                        {t('admin.users.empty.cta')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={users.data}
                            getRowId={(user) => String(user.id)}
                            sort={{ id: sort, direction }}
                            onSortChange={changeSort}
                            pagination={users}
                            onRowClick={openEdit}
                            getRowProps={(user) => ({
                                className: user.can_update
                                    ? 'cursor-pointer'
                                    : undefined,
                            })}
                        />
                    )}
                </PageLayout>

                <UserFormDrawer
                    editing={editing}
                    open={drawerOpen}
                    readOnly={editing ? !editing.can_update : !canManageUsers}
                    onCancel={() => handleDrawerOpenChange(false)}
                    onSuccess={() => {
                        setDrawerOpen(false);
                        setEditing(null);
                    }}
                />
            </Drawer>

            <ConfirmDestructiveDialog
                open={pendingAction?.type === 'deactivate'}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingAction(null);
                    }
                }}
                title={t('admin.users.confirm.deactivateTitle', {
                    name: pendingAction?.user.name ?? '',
                })}
                description={t('admin.users.confirm.deactivateDescription')}
                confirmLabel={t('admin.users.actions.deactivate')}
                confirming={isConfirming}
                onConfirm={confirmPendingAction}
            />

            <ConfirmDestructiveDialog
                open={pendingAction?.type === 'resetTwoFactor'}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingAction(null);
                    }
                }}
                title={t('admin.users.confirm.resetTwoFactorTitle', {
                    name: pendingAction?.user.name ?? '',
                })}
                description={t('admin.users.confirm.resetTwoFactorDescription')}
                confirmLabel={t('admin.users.actions.resetTwoFactor')}
                confirming={isConfirming}
                onConfirm={confirmPendingAction}
            />

            <ConfirmDestructiveDialog
                open={pendingAction?.type === 'revokeInstanceAdmin'}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingAction(null);
                    }
                }}
                title={t('admin.users.confirm.revokeInstanceAdminTitle', {
                    name: pendingAction?.user.name ?? '',
                })}
                description={t(
                    'admin.users.confirm.revokeInstanceAdminDescription',
                )}
                confirmLabel={t('admin.users.actions.revokeInstanceAdmin')}
                confirming={isConfirming}
                onConfirm={confirmPendingAction}
            />
        </AppLayout>
    );
}
