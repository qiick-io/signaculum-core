import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { STRING_LIMITS } from '@/lib/string-limits';
import {
    store as storeGroup,
    update as updateGroup,
} from '@/routes/admin/groups';
import type { AdminGroupRow, AdminRoleOption, AdminUserRef } from '@/types/admin';

const EMPTY_GROUP_FORM = {
    name: '',
    description: '',
    role_ids: [] as number[],
    user_ids: [] as number[],
};

export type GroupFormDrawerProps = {
    editing: AdminGroupRow | null;
    roles: AdminRoleOption[];
    users: AdminUserRef[];
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing user groups (externa-core shape, adapted
 * to Signaculum without paginated multi-selects).
 */
export function GroupFormDrawer({
    editing,
    roles,
    users,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: GroupFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_GROUP_FORM });

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload = {
                name: editing.name,
                description: editing.description ?? '',
                role_ids: editing.role_ids ?? editing.roles.map((role) => role.id),
                user_ids:
                    editing.user_ids ??
                    editing.users?.map((user) => user.id) ??
                    [],
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_GROUP_FORM });
            form.reset();
            form.clearErrors();
        }
        // Inertia form identity is unstable across renders.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional
    }, [editing, open]);

    const toggleId = (
        field: 'role_ids' | 'user_ids',
        id: number,
        isChecked: boolean,
    ): void => {
        const current = form.data[field];
        form.setData(
            field,
            isChecked
                ? [...current, id]
                : current.filter((value) => value !== id),
        );
    };

    const submit = (): void => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        };

        if (editing) {
            form.put(updateGroup.url(editing.id), opts);
        } else {
            form.post(storeGroup.url(), opts);
        }
    };

    const title = editing
        ? t('admin.groups.form.editTitle')
        : t('admin.groups.newGroup');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {t('admin.groups.form.description')}
                </DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();

                    if (!readOnly) {
                        submit();
                    }
                }}
            >
                <DrawerBody>
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="group_name">
                                {t('admin.groups.columns.name')}
                            </FieldLabel>
                            <Input
                                id="group_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.NAME}
                                disabled={readOnly}
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.description ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="group_description">
                                {t('admin.groups.form.descriptionLabel')}
                            </FieldLabel>
                            <Input
                                id="group_description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                maxLength={STRING_LIMITS.DESCRIPTION}
                                disabled={readOnly}
                            />
                            <FieldError>{form.errors.description}</FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.role_ids ? true : undefined
                            }
                        >
                            <FieldLabel>
                                {t('admin.groups.columns.roles')}
                            </FieldLabel>
                            <FieldDescription>
                                {t('admin.groups.form.rolesHint')}
                            </FieldDescription>
                            <div className="mt-2 flex max-h-40 flex-col gap-2 overflow-y-auto rounded-md border p-3">
                                {roles.map((role) => {
                                    const isChecked =
                                        form.data.role_ids.includes(role.id);

                                    return (
                                        <label
                                            key={role.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={isChecked}
                                                disabled={readOnly}
                                                onCheckedChange={(checked) =>
                                                    toggleId(
                                                        'role_ids',
                                                        role.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <span>{role.name}</span>
                                        </label>
                                    );
                                })}
                                {roles.length === 0 ? (
                                    <span className="text-muted-foreground text-sm">
                                        {t('admin.groups.form.noRoles')}
                                    </span>
                                ) : null}
                            </div>
                            <FieldError>{form.errors.role_ids}</FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.user_ids ? true : undefined
                            }
                        >
                            <FieldLabel>
                                {t('admin.groups.form.users')}
                            </FieldLabel>
                            <div className="mt-2 flex max-h-48 flex-col gap-2 overflow-y-auto rounded-md border p-3">
                                {users.map((user) => {
                                    const isChecked =
                                        form.data.user_ids.includes(user.id);

                                    return (
                                        <label
                                            key={user.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={isChecked}
                                                disabled={readOnly}
                                                onCheckedChange={(checked) =>
                                                    toggleId(
                                                        'user_ids',
                                                        user.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <span>
                                                {user.name}{' '}
                                                <span className="text-muted-foreground">
                                                    ({user.email})
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                                {users.length === 0 ? (
                                    <span className="text-muted-foreground text-sm">
                                        {t('admin.groups.form.noUsers')}
                                    </span>
                                ) : null}
                            </div>
                            <FieldError>{form.errors.user_ids}</FieldError>
                        </Field>
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        {t('common.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {editing
                                ? t('common.save')
                                : t('admin.groups.form.create')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
