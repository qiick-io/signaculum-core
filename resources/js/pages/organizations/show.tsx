import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import OrganizationMemberController from '@/actions/App/Http/Controllers/OrganizationMemberController';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import Heading from '@/components/heading';
import { PageLayout } from '@/components/layout/page-layout';
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
    organization: {
        id: string;
        name: string;
        slug: string;
    };
    members: OrganizationMember[];
    can: {
        update: boolean;
        delete: boolean;
        manageMembers: boolean;
    };
};

/**
 * Organisation detail: settings for owners/admins, member list with multi-role edits.
 */
export default function OrganizationShow({
    organization,
    members,
    can,
}: OrganizationShowProps) {
    const { t } = useTranslation();
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
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

    const confirmDelete = () => {
        setIsDeleting(true);
        router.delete(
            OrganizationController.destroy.url({
                organization: organization.slug,
            }),
            {
                onFinish: () => {
                    setIsDeleting(false);
                    setIsDeleteOpen(false);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={organization.name} />

            <h1 className="sr-only">{organization.name}</h1>

            <PageLayout scrollContent>
                <div className="space-y-10">
                    <Heading
                        title={organization.name}
                        description={t('organizations.showDescription')}
                    />

                    {can.update ? (
                        <Form
                            {...OrganizationController.update.form({
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
                                            errors.name ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="name">
                                            {t('common.name')}
                                        </FieldLabel>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={organization.name}
                                            required
                                        />
                                        <FieldError>{errors.name}</FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.slug ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="slug">
                                            {t('organizations.slug')}
                                        </FieldLabel>
                                        <Input
                                            id="slug"
                                            name="slug"
                                            defaultValue={organization.slug}
                                            required
                                        />
                                        <FieldError>{errors.slug}</FieldError>
                                    </Field>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit"
                                    >
                                        {t('common.save')}
                                    </Button>
                                </FieldGroup>
                            )}
                        </Form>
                    ) : null}

                    <div className="space-y-4">
                        <Heading
                            variant="small"
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
                                        <TableHead>
                                            {t('common.actions')}
                                        </TableHead>
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
                                                                    member
                                                                        .user_id
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
                                                    {member.roles.map(
                                                        (role) => (
                                                            <Badge
                                                                key={role}
                                                                variant="secondary"
                                                            >
                                                                {roleLabel(
                                                                    role,
                                                                )}
                                                            </Badge>
                                                        ),
                                                    )}
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
                                                errors.user_id
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="user_id">
                                                {t(
                                                    'organizations.members.userId',
                                                )}
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
                                                processing ||
                                                addRoles.length === 0
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

                    {can.delete ? (
                        <div className="space-y-3">
                            <Heading
                                variant="small"
                                title={t('organizations.danger.title')}
                                description={t(
                                    'organizations.danger.description',
                                )}
                            />
                            <Button
                                type="button"
                                variant="destructive"
                                className="w-fit"
                                onClick={() => setIsDeleteOpen(true)}
                            >
                                {t('common.delete')}
                            </Button>
                        </div>
                    ) : null}
                </div>
            </PageLayout>

            <ConfirmDestructiveDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                title={t('organizations.danger.confirmTitle')}
                description={t('organizations.danger.confirmDescription')}
                confirmLabel={t('common.delete')}
                typedConfirmation={organization.name}
                confirming={isDeleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
