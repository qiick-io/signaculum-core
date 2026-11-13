import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import OrganizationMemberController from '@/actions/App/Http/Controllers/OrganizationMemberController';
import Heading from '@/components/heading';
import { PageLayout } from '@/components/layout/page-layout';
import {
    OrganizationViewTabs,
    type OrganizationView,
} from '@/components/organizations/organization-view-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    index as organizationsIndex,
    show as organizationsShow,
} from '@/routes/organizations';
import type { BreadcrumbItem } from '@/types';

const ORGANIZATION_ROLES = ['owner', 'admin', 'member', 'billing'] as const;

type OrganizationRole = (typeof ORGANIZATION_ROLES)[number];

type OrganizationMember = {
    user_id: number;
    name: string;
    email: string | null;
    roles: OrganizationRole[];
};

type OrganizationShowProps = {
    view: OrganizationView;
    organization: {
        id: string;
        name: string;
        slug: string;
    };
    members: OrganizationMember[];
    can: {
        manageMembers: boolean;
    };
};

/**
 * Organisation Members view: list, multi-role edits, and add by user id.
 */
export default function OrganizationShow({
    view,
    organization,
    members,
    can,
}: OrganizationShowProps) {
    const { t } = useTranslation();
    const [memberRoles, setMemberRoles] = useState<
        Record<number, OrganizationRole[]>
    >(() =>
        Object.fromEntries(
            members.map((member) => [member.user_id, [...member.roles]]),
        ),
    );
    const [addRoles, setAddRoles] = useState<OrganizationRole[]>(['member']);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('organizations.breadcrumb'),
            href: organizationsIndex(),
        },
        {
            title: organization.name,
            href: organizationsShow(organization.slug),
        },
    ];

    const roleLabel = (role: OrganizationRole): string =>
        t(`organizations.members.roles.${role}`);

    const toggleRole = (
        current: OrganizationRole[],
        role: OrganizationRole,
        isChecked: boolean,
    ): OrganizationRole[] => {
        if (isChecked) {
            return current.includes(role) ? current : [...current, role];
        }

        return current.filter((entry) => entry !== role);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={organization.name} />

            <h1 className="sr-only">{organization.name}</h1>

            <OrganizationViewTabs
                organizationSlug={organization.slug}
                view={view}
            />

            <PageLayout scrollContent>
                <div className="space-y-6">
                    <Heading
                        title={t('organizations.members.title')}
                        description={t('organizations.members.description')}
                    />

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('common.name')}</TableHead>
                                <TableHead>{t('common.email')}</TableHead>
                                <TableHead>
                                    {t('organizations.members.rolesLabel')}
                                </TableHead>
                                {can.manageMembers ? (
                                    <TableHead>{t('common.actions')}</TableHead>
                                ) : null}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => (
                                <TableRow key={member.user_id}>
                                    <TableCell>{member.name}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {member.email}
                                    </TableCell>
                                    <TableCell>
                                        {can.manageMembers ? (
                                            <div className="flex flex-wrap gap-3">
                                                {ORGANIZATION_ROLES.map(
                                                    (role) => {
                                                        const isChecked = (
                                                            memberRoles[
                                                                member.user_id
                                                            ] ?? []
                                                        ).includes(role);

                                                        return (
                                                            <label
                                                                key={role}
                                                                className="flex items-center gap-2 text-sm"
                                                            >
                                                                <Checkbox
                                                                    checked={
                                                                        isChecked
                                                                    }
                                                                    onCheckedChange={(
                                                                        checked,
                                                                    ) => {
                                                                        setMemberRoles(
                                                                            (
                                                                                previous,
                                                                            ) => ({
                                                                                ...previous,
                                                                                [member.user_id]:
                                                                                    toggleRole(
                                                                                        previous[
                                                                                            member
                                                                                                .user_id
                                                                                        ] ??
                                                                                            [],
                                                                                        role,
                                                                                        checked ===
                                                                                            true,
                                                                                    ),
                                                                            }),
                                                                        );
                                                                    }}
                                                                />
                                                                {roleLabel(
                                                                    role,
                                                                )}
                                                            </label>
                                                        );
                                                    },
                                                )}
                                            </div>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {member.roles.map((role) => (
                                                    <Badge
                                                        key={role}
                                                        variant="secondary"
                                                    >
                                                        {roleLabel(role)}
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </TableCell>
                                    {can.manageMembers ? (
                                        <TableCell>
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => {
                                                        router.put(
                                                            OrganizationMemberController.update.url(
                                                                {
                                                                    organization:
                                                                        organization.slug,
                                                                    user: member.user_id,
                                                                },
                                                            ),
                                                            {
                                                                roles:
                                                                    memberRoles[
                                                                        member
                                                                            .user_id
                                                                    ] ?? [],
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {t('common.save')}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="destructive"
                                                    onClick={() => {
                                                        router.delete(
                                                            OrganizationMemberController.destroy.url(
                                                                {
                                                                    organization:
                                                                        organization.slug,
                                                                    user: member.user_id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {t(
                                                        'organizations.members.remove',
                                                    )}
                                                </Button>
                                            </div>
                                        </TableCell>
                                    ) : null}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>

                    {can.manageMembers ? (
                        <Form
                            {...OrganizationMemberController.store.form({
                                organization: organization.slug,
                            })}
                            options={{ preserveScroll: true }}
                            className="max-w-xl space-y-4"
                            noValidate
                        >
                            {({ processing, errors }) => (
                                <FieldGroup className="gap-4">
                                    <Field
                                        data-invalid={
                                            errors.user_id ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="user_id">
                                            {t('organizations.members.userId')}
                                        </FieldLabel>
                                        <Input
                                            id="user_id"
                                            name="user_id"
                                            type="number"
                                            required
                                        />
                                        <FieldError>
                                            {errors.user_id}
                                        </FieldError>
                                    </Field>

                                    {addRoles.map((role) => (
                                        <input
                                            key={role}
                                            type="hidden"
                                            name="roles[]"
                                            value={role}
                                        />
                                    ))}

                                    <div className="flex flex-wrap gap-3">
                                        {ORGANIZATION_ROLES.map((role) => (
                                            <label
                                                key={role}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={addRoles.includes(
                                                        role,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) => {
                                                        setAddRoles(
                                                            (previous) =>
                                                                toggleRole(
                                                                    previous,
                                                                    role,
                                                                    checked ===
                                                                        true,
                                                                ),
                                                        );
                                                    }}
                                                />
                                                {roleLabel(role)}
                                            </label>
                                        ))}
                                    </div>
                                    <FieldError>{errors.roles}</FieldError>

                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || addRoles.length === 0
                                        }
                                        className="w-fit"
                                    >
                                        {t('organizations.members.add')}
                                    </Button>
                                </FieldGroup>
                            )}
                        </Form>
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
