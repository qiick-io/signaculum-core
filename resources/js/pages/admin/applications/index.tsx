import type { ColumnDef } from '@tanstack/react-table';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AppWindow,
    ArrowDownAZ,
    ArrowUpAZ,
    Pencil,
    Plus,
    SearchX,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApplicationFormDrawer } from '@/components/admin/application-form-drawer';
import { DataTable } from '@/components/admin/data-table';
import type { DataTableSort } from '@/components/admin/data-table';
import { DataTableColumnHeader } from '@/components/admin/data-table-column-header';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
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
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import AppLayout from '@/layouts/app-layout';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { index as applicationsIndex } from '@/routes/admin/applications';
import type { AdminApplicationRow, BreadcrumbItem, Paginated } from '@/types';

type ApplicationSortField = 'name' | 'created_at' | 'updated_at';
type ApplicationSortDirection = 'asc' | 'desc';

const APPLICATION_SORT_FIELDS: ApplicationSortField[] = [
    'name',
    'created_at',
    'updated_at',
];

function isApplicationSortField(value: string): value is ApplicationSortField {
    return (APPLICATION_SORT_FIELDS as string[]).includes(value);
}

type Filters = {
    search?: string;
    sort?: ApplicationSortField;
    direction?: ApplicationSortDirection;
};

/**
 * Admin → Applications: OIDC clients list with search, sort, pagination and a
 * create/edit drawer. Pattern from app-ui `list-page.md` (same as Admin → Users).
 */
export default function AdminApplicationsIndex({
    applications: applicationsProp,
    filters = {},
}: {
    applications:
        | LaravelPaginated<AdminApplicationRow>
        | Paginated<AdminApplicationRow>;
    filters?: Filters;
}) {
    const applications = normalizePaginated(applicationsProp);
    const { t } = useTranslation();
    const { canManageApplications } = usePage().props.auth;

    const applicationSortFields: {
        value: ApplicationSortField;
        label: string;
    }[] = [
        { value: 'name', label: t('common.name') },
        {
            value: 'created_at',
            label: t('admin.applications.columns.created'),
        },
        {
            value: 'updated_at',
            label: t('admin.applications.columns.updated'),
        },
    ];

    const [search, setSearch] = useState(filters.search ?? '');
    const [sort, setSort] = useState<ApplicationSortField>(
        filters.sort ?? 'created_at',
    );
    const [direction, setDirection] = useState<ApplicationSortDirection>(
        filters.direction ?? 'desc',
    );
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<AdminApplicationRow | null>(null);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('admin.applications.title'),
                href: applicationsIndex.url(),
            },
        ],
        [t],
    );

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextSort = overrides.sort ?? sort;
            const nextDirection = overrides.direction ?? direction;

            router.get(
                applicationsIndex.url({
                    query: {
                        search: nextSearch || undefined,
                        sort: nextSort,
                        direction: nextDirection,
                        per_page: listPerPageQuery(applications.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [applications.per_page, direction, search, sort],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
        setSort(filters.sort ?? 'created_at');
        setDirection(filters.direction ?? 'desc');
    }, [filters.search, filters.sort, filters.direction]);

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

    const openEdit = (application: AdminApplicationRow): void => {
        if (!application.can_update) {
            return;
        }

        setEditing(application);
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

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = applications.data.length === 0;
    const hideListChrome = isListEmpty && !hasActiveFilters;

    const changeSort = (nextSort: DataTableSort): void => {
        if (!isApplicationSortField(nextSort.id)) {
            return;
        }

        setSort(nextSort.id);
        setDirection(nextSort.direction);
        visit({ sort: nextSort.id, direction: nextSort.direction });
    };

    const columns: ColumnDef<AdminApplicationRow>[] = [
        {
            accessorKey: 'name',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[16rem] font-medium' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('admin.applications.columns.name')}
                />
            ),
            cell: ({ row }) => <TruncatedText text={row.original.name} />,
        },
        {
            id: 'type',
            header: t('admin.applications.columns.type'),
            cell: ({ row }) => (
                <div className="flex flex-wrap gap-1.5">
                    <Badge variant="muted">
                        {row.original.is_confidential
                            ? t('admin.applications.type.confidential')
                            : t('admin.applications.type.public')}
                    </Badge>
                    {row.original.is_first_party ? (
                        <Badge variant="success">
                            {t('admin.applications.type.firstParty')}
                        </Badge>
                    ) : null}
                </div>
            ),
        },
        {
            id: 'redirect_uris',
            header: t('admin.applications.columns.redirectUris'),
            meta: { cellClassName: 'max-w-[18rem] text-muted-foreground' },
            cell: ({ row }) => (
                <TruncatedText
                    text={t('admin.applications.redirectUriCount', {
                        count: row.original.redirect_uris.length,
                    })}
                />
            ),
        },
        {
            id: 'actions',
            header: t('admin.applications.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) =>
                row.original.can_update ? (
                    <ButtonGroup
                        aria-label={t('admin.applications.columns.actions')}
                        className="ml-auto"
                    >
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            label={t('admin.applications.actions.edit')}
                            onClick={() => openEdit(row.original)}
                        >
                            <Pencil className="size-3.5" />
                        </HeaderIconButton>
                    </ButtonGroup>
                ) : null,
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                canManageApplications ? (
                    <Button type="button" onClick={openCreate}>
                        <Plus className="mr-1 size-4" />
                        {t('admin.applications.newApplication')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('admin.applications.head')} />
            <h1 className="sr-only">{t('admin.applications.head')}</h1>

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
                                    'admin.applications.searchPlaceholder',
                                )}
                            />
                        )
                    }
                    filtersRight={
                        hideListChrome ? null : (
                            <div className="flex items-center gap-1.5">
                                <Select
                                    value={sort}
                                    onValueChange={(value) => {
                                        if (isApplicationSortField(value)) {
                                            setSort(value);
                                            visit({ sort: value });
                                        }
                                    }}
                                >
                                    <SelectTrigger
                                        aria-label={t(
                                            'admin.applications.sortBy',
                                        )}
                                        className="w-[7.5rem]"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'admin.applications.sortBy',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        {applicationSortFields.map((field) => (
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
                            icon={hasActiveFilters ? SearchX : AppWindow}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t(
                                              'admin.applications.empty.filteredTitle',
                                          )
                                        : t('admin.applications.empty.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t(
                                          'admin.applications.empty.filteredDescription',
                                      )
                                    : t('admin.applications.empty.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            visit({ search: undefined });
                                        }}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : canManageApplications ? (
                                    <Button type="button" onClick={openCreate}>
                                        <Plus className="size-4" />
                                        {t('admin.applications.empty.cta')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={applications.data}
                            getRowId={(application) => application.id}
                            sort={{ id: sort, direction }}
                            onSortChange={changeSort}
                            pagination={applications}
                            onRowClick={openEdit}
                            getRowProps={(application) => ({
                                className: application.can_update
                                    ? 'cursor-pointer'
                                    : undefined,
                            })}
                        />
                    )}
                </PageLayout>

                <ApplicationFormDrawer
                    editing={editing}
                    open={drawerOpen}
                    readOnly={
                        editing ? !editing.can_update : !canManageApplications
                    }
                    onCancel={() => handleDrawerOpenChange(false)}
                    onSuccess={() => {
                        setDrawerOpen(false);
                        setEditing(null);
                    }}
                />
            </Drawer>
        </AppLayout>
    );
}
