import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDownAZ,
    ArrowUpAZ,
    Pencil,
    Plus,
    RotateCcw,
    SearchX,
    Trash2,
    UsersRound,
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
import { GroupFormDrawer } from '@/components/admin/group-form-drawer';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
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
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import AppLayout from '@/layouts/app-layout';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import {
    destroy as destroyGroup,
    forceDestroy,
    index as groupsIndex,
    restore as restoreGroup,
} from '@/routes/admin/groups';
import type {
    AdminGroupRow,
    AdminRoleOption,
    AdminUserRef,
    BreadcrumbItem,
    Paginated,
} from '@/types';

type PendingGroupAction =
    | { type: 'delete'; group: AdminGroupRow }
    | { type: 'forceDelete'; group: AdminGroupRow }
    | null;

type GroupSortField = 'name' | 'created_at' | 'updated_at';
type GroupSortDirection = 'asc' | 'desc';

const GROUP_SORT_FIELDS: GroupSortField[] = [
    'name',
    'created_at',
    'updated_at',
];

function isGroupSortField(value: string): value is GroupSortField {
    return (GROUP_SORT_FIELDS as string[]).includes(value);
}

type Filters = {
    search?: string;
    trashed?: boolean;
    sort?: GroupSortField;
    direction?: GroupSortDirection;
};

/**
 * Admin → Groups: list with search, trash toggle, sort, pagination and a
 * create/edit drawer (Signaculum users-list shape / app-ui list-page).
 */
export default function AdminGroupsIndex({
    groups: groupsProp,
    filters = {},
    roles = [],
    users = [],
}: {
    groups: LaravelPaginated<AdminGroupRow> | Paginated<AdminGroupRow>;
    filters?: Filters;
    roles?: AdminRoleOption[];
    users?: AdminUserRef[];
}) {
    const groups = normalizePaginated(groupsProp);
    const { t } = useTranslation();
    const { can } = useCan();

    const groupSortFields: { value: GroupSortField; label: string }[] = [
        { value: 'name', label: t('common.name') },
        { value: 'created_at', label: t('admin.groups.columns.created') },
        { value: 'updated_at', label: t('admin.groups.columns.updated') },
    ];

    const [search, setSearch] = useState(filters.search ?? '');
    const [isTrashed, setIsTrashed] = useState(filters.trashed ?? false);
    const [sort, setSort] = useState<GroupSortField>(filters.sort ?? 'name');
    const [direction, setDirection] = useState<GroupSortDirection>(
        filters.direction ?? 'asc',
    );
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<AdminGroupRow | null>(null);
    const [pendingAction, setPendingAction] =
        useState<PendingGroupAction>(null);
    const [isConfirming, setIsConfirming] = useState(false);

    const canCreate = can(PermissionEnum.CanCreateGroups);
    const canEdit = can(PermissionEnum.CanEditGroups);
    const canDelete = can(PermissionEnum.CanDeleteGroups);
    const canRestore = can(PermissionEnum.CanRestoreGroups);
    const canForceDelete = can(PermissionEnum.CanForceDeleteGroups);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [{ title: t('admin.groups.title'), href: groupsIndex.url() }],
        [t],
    );

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextTrashed = overrides.trashed ?? isTrashed;
            const nextSort = overrides.sort ?? sort;
            const nextDirection = overrides.direction ?? direction;

            router.get(
                groupsIndex.url({
                    query: {
                        search: nextSearch || undefined,
                        trashed: nextTrashed ? 1 : undefined,
                        sort: nextSort,
                        direction: nextDirection,
                        per_page: listPerPageQuery(groups.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [direction, groups.per_page, isTrashed, search, sort],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
        setIsTrashed(filters.trashed ?? false);
        setSort(filters.sort ?? 'name');
        setDirection(filters.direction ?? 'asc');
    }, [filters.search, filters.trashed, filters.sort, filters.direction]);

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

    const openEdit = (group: AdminGroupRow): void => {
        if (isTrashed || !canEdit) {
            return;
        }

        setEditing(group);
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

    const hasActiveFilters = search.trim() !== '' || isTrashed;
    const isListEmpty = groups.data.length === 0;
    const hideListChrome = isListEmpty && !hasActiveFilters;

    const changeSort = (nextSort: DataTableSort): void => {
        if (!isGroupSortField(nextSort.id)) {
            return;
        }

        setSort(nextSort.id);
        setDirection(nextSort.direction);
        visit({ sort: nextSort.id, direction: nextSort.direction });
    };

    const rowActionsFor = useCallback((): OverflowActionDefinition[] => {
        if (isTrashed) {
            const actions: OverflowActionDefinition[] = [];

            if (canRestore) {
                actions.push({
                    key: 'restore',
                    label: t('admin.groups.actions.restore'),
                    icon: RotateCcw,
                });
            }

            if (canForceDelete) {
                actions.push({
                    key: 'forceDelete',
                    label: t('admin.groups.actions.forceDelete'),
                    icon: Trash2,
                    destructive: true,
                });
            }

            return actions;
        }

        if (!canDelete) {
            return [];
        }

        return [
            {
                key: 'delete',
                label: t('admin.groups.actions.delete'),
                icon: Trash2,
                destructive: true,
            },
        ];
    }, [canDelete, canForceDelete, canRestore, isTrashed, t]);

    const handleRowAction = useCallback(
        (group: AdminGroupRow, key: string): void => {
            if (key === 'delete') {
                setPendingAction({ type: 'delete', group });

                return;
            }

            if (key === 'forceDelete') {
                setPendingAction({ type: 'forceDelete', group });

                return;
            }

            if (key === 'restore') {
                router.post(
                    restoreGroup.url(group.id),
                    {},
                    { preserveScroll: true },
                );
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

        if (pendingAction.type === 'forceDelete') {
            router.delete(forceDestroy.url(pendingAction.group.id), finish);

            return;
        }

        router.delete(destroyGroup.url(pendingAction.group.id), finish);
    };

    const columns: ColumnDef<AdminGroupRow>[] = [
        {
            accessorKey: 'name',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('admin.groups.columns.name')}
                />
            ),
            cell: ({ row }) => <TruncatedText text={row.original.name} />,
        },
        {
            id: 'description',
            enableSorting: false,
            meta: { cellClassName: 'max-w-[16rem] text-muted-foreground' },
            header: t('admin.groups.columns.description'),
            cell: ({ row }) =>
                row.original.description ? (
                    <TruncatedText text={row.original.description} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            id: 'members',
            header: t('admin.groups.columns.members'),
            cell: ({ row }) => row.original.users_count,
        },
        {
            id: 'roles',
            header: t('admin.groups.columns.roles'),
            cell: ({ row }) => row.original.roles_count,
        },
        {
            id: 'actions',
            header: t('admin.groups.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) => {
                const actions = rowActionsFor();
                const showEdit = !isTrashed && canEdit;

                if (!showEdit && actions.length === 0) {
                    return null;
                }

                return (
                    <ButtonGroup
                        aria-label={t('admin.groups.columns.actions')}
                        className="ml-auto"
                    >
                        {showEdit ? (
                            <HeaderIconButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                label={t('admin.groups.actions.edit')}
                                onClick={() => openEdit(row.original)}
                            >
                                <Pencil className="size-3.5" />
                            </HeaderIconButton>
                        ) : null}
                        {actions.length > 0 ? (
                            <ActionsOverflow
                                iconOnly
                                actions={actions}
                                onAction={(key) =>
                                    handleRowAction(row.original, key)
                                }
                            />
                        ) : null}
                    </ButtonGroup>
                );
            },
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                canCreate && !isTrashed ? (
                    <Button type="button" onClick={openCreate}>
                        <Plus className="mr-1 size-4" />
                        {t('admin.groups.newGroup')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('admin.groups.head')} />
            <h1 className="sr-only">{t('admin.groups.head')}</h1>

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
                                    'admin.groups.searchPlaceholder',
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
                                    value={isTrashed ? 'trashed' : 'active'}
                                    onValueChange={(value) => {
                                        if (
                                            value === 'active' ||
                                            value === 'trashed'
                                        ) {
                                            const nextTrashed =
                                                value === 'trashed';
                                            setIsTrashed(nextTrashed);
                                            visit({ trashed: nextTrashed });
                                        }
                                    }}
                                >
                                    <ToggleGroupItem value="active">
                                        {t('admin.groups.status.active')}
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="trashed">
                                        {t('admin.groups.status.trashed')}
                                    </ToggleGroupItem>
                                </ToggleGroup>
                                <Select
                                    value={sort}
                                    onValueChange={(value) => {
                                        if (isGroupSortField(value)) {
                                            setSort(value);
                                            visit({ sort: value });
                                        }
                                    }}
                                >
                                    <SelectTrigger
                                        aria-label={t('admin.groups.sortBy')}
                                        className="w-[7.5rem]"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'admin.groups.sortBy',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        {groupSortFields.map((field) => (
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
                            icon={hasActiveFilters ? SearchX : UsersRound}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('admin.groups.empty.filteredTitle')
                                        : t('admin.groups.empty.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t(
                                          'admin.groups.empty.filteredDescription',
                                      )
                                    : t('admin.groups.empty.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            setIsTrashed(false);
                                            visit({
                                                search: undefined,
                                                trashed: false,
                                            });
                                        }}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : canCreate ? (
                                    <Button type="button" onClick={openCreate}>
                                        <Plus className="size-4" />
                                        {t('admin.groups.empty.cta')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={groups.data}
                            getRowId={(group) => String(group.id)}
                            sort={{ id: sort, direction }}
                            onSortChange={changeSort}
                            pagination={groups}
                            onRowClick={isTrashed ? undefined : openEdit}
                            getRowProps={() => ({
                                className:
                                    !isTrashed && canEdit
                                        ? 'cursor-pointer'
                                        : undefined,
                            })}
                        />
                    )}
                </PageLayout>

                <GroupFormDrawer
                    editing={editing}
                    roles={roles}
                    users={users}
                    open={drawerOpen}
                    readOnly={editing ? !canEdit : !canCreate}
                    onCancel={() => handleDrawerOpenChange(false)}
                    onSuccess={() => {
                        setDrawerOpen(false);
                        setEditing(null);
                    }}
                />
            </Drawer>

            <ConfirmDestructiveDialog
                open={pendingAction?.type === 'delete'}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingAction(null);
                    }
                }}
                title={t('admin.groups.confirm.deleteTitle', {
                    name: pendingAction?.group.name ?? '',
                })}
                description={t('admin.groups.confirm.deleteDescription')}
                confirmLabel={t('admin.groups.actions.delete')}
                confirming={isConfirming}
                onConfirm={confirmPendingAction}
            />

            <ConfirmDestructiveDialog
                open={pendingAction?.type === 'forceDelete'}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingAction(null);
                    }
                }}
                title={t('admin.groups.confirm.forceDeleteTitle', {
                    name: pendingAction?.group.name ?? '',
                })}
                description={t('admin.groups.confirm.forceDeleteDescription')}
                confirmLabel={t('admin.groups.actions.forceDelete')}
                confirming={isConfirming}
                onConfirm={confirmPendingAction}
            />
        </AppLayout>
    );
}
