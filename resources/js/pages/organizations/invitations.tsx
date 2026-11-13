import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import OrganizationInvitationController from '@/actions/App/Http/Controllers/OrganizationInvitationController';
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

type InvitationRow = {
    id: number;
    email: string;
    roles: OrganizationRole[];
    expires_at: string | null;
    is_expired: boolean;
    created_at: string | null;
};

type OrganizationInvitationsProps = {
    view: OrganizationView;
    organization: {
        id: string;
        name: string;
        slug: string;
    };
    invitations: InvitationRow[];
    can: {
        manageMembers: boolean;
    };
};

/**
 * Organisation Invitations view: pending invites, send, resend, revoke.
 */
export default function OrganizationInvitations({
    view,
    organization,
    invitations,
    can,
}: OrganizationInvitationsProps) {
    const { t } = useTranslation();
    const [inviteRoles, setInviteRoles] = useState<OrganizationRole[]>([
        'member',
    ]);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('organizations.breadcrumb'),
            href: organizationsIndex(),
        },
        {
            title: organization.name,
            href: organizationsShow(organization.slug),
        },
        {
            title: t('organizations.views.invitations'),
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
            <Head
                title={t('organizations.invitations.head', {
                    name: organization.name,
                })}
            />

            <h1 className="sr-only">{organization.name}</h1>

            <OrganizationViewTabs
                organizationSlug={organization.slug}
                view={view}
            />

            <PageLayout scrollContent>
                <div className="space-y-6">
                    <Heading
                        title={t('organizations.invitations.title')}
                        description={t('organizations.invitations.description')}
                    />

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('common.email')}</TableHead>
                                <TableHead>
                                    {t('organizations.members.rolesLabel')}
                                </TableHead>
                                <TableHead>
                                    {t('organizations.invitations.expires')}
                                </TableHead>
                                {can.manageMembers ? (
                                    <TableHead>{t('common.actions')}</TableHead>
                                ) : null}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invitations.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={can.manageMembers ? 4 : 3}
                                        className="text-muted-foreground"
                                    >
                                        {t('organizations.invitations.empty')}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                invitations.map((invitation) => (
                                    <TableRow key={invitation.id}>
                                        <TableCell>
                                            {invitation.email}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {invitation.roles.map(
                                                    (role) => (
                                                        <Badge
                                                            key={role}
                                                            variant="secondary"
                                                        >
                                                            {roleLabel(role)}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {invitation.is_expired
                                                ? t(
                                                      'organizations.invitations.expired',
                                                  )
                                                : invitation.expires_at}
                                        </TableCell>
                                        {can.manageMembers ? (
                                            <TableCell>
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => {
                                                            router.post(
                                                                OrganizationInvitationController.resend.url(
                                                                    {
                                                                        organization:
                                                                            organization.slug,
                                                                        invitation:
                                                                            invitation.id,
                                                                    },
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        {t(
                                                            'organizations.invitations.resend',
                                                        )}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() => {
                                                            router.delete(
                                                                OrganizationInvitationController.destroy.url(
                                                                    {
                                                                        organization:
                                                                            organization.slug,
                                                                        invitation:
                                                                            invitation.id,
                                                                    },
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        {t(
                                                            'organizations.invitations.revoke',
                                                        )}
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        ) : null}
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    {can.manageMembers ? (
                        <Form
                            {...OrganizationInvitationController.store.form({
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
                                            errors.email ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="email">
                                            {t('common.email')}
                                        </FieldLabel>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            autoComplete="off"
                                        />
                                        <FieldError>{errors.email}</FieldError>
                                    </Field>

                                    {inviteRoles.map((role) => (
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
                                                    checked={inviteRoles.includes(
                                                        role,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) => {
                                                        setInviteRoles(
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
                                            inviteRoles.length === 0
                                        }
                                        className="w-fit"
                                    >
                                        {t('organizations.invitations.send')}
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
