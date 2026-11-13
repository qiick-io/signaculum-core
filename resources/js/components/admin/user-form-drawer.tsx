import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
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
    store as storeUser,
    update as updateUser,
} from '@/routes/admin/users';
import type { AdminUserRow } from '@/types/admin';

const EMPTY_USER_FORM = {
    first_name: '',
    last_name: '',
    email: '',
    password: '',
};

export type UserFormDrawerProps = {
    editing: AdminUserRow | null;
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing IdP users (app-ui `forms.md`, copied from
 * externa-core `user-form-drawer.tsx` without roles/groups/activation).
 */
export function UserFormDrawer({
    editing,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: UserFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_USER_FORM });
    const isCreate = editing === null;

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload = {
                first_name: editing.first_name,
                last_name: editing.last_name ?? '',
                email: editing.email,
                password: '',
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_USER_FORM });
            form.reset();
            form.clearErrors();
        }
        // Inertia form identity is unstable across renders.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional
    }, [editing, open]);

    const submit = (): void => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        };

        if (editing) {
            form.transform((data) => ({
                ...data,
                password: data.password || undefined,
            }));
            form.put(updateUser.url(editing.id), opts);
        } else {
            form.post(storeUser.url(), opts);
        }
    };

    const title = editing
        ? t('admin.users.form.editTitle')
        : t('admin.users.newUser');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {editing
                        ? t('admin.users.form.editDescription')
                        : t('admin.users.form.createDescription')}
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
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t('admin.users.form.requiredHint')}
                    </p>
                    <FieldGroup className="gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                data-invalid={
                                    form.errors.first_name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="user_first_name">
                                    {t('admin.users.form.firstNameRequired')}
                                </FieldLabel>
                                <Input
                                    id="user_first_name"
                                    value={form.data.first_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'first_name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    maxLength={STRING_LIMITS.NAME}
                                    disabled={readOnly}
                                    aria-invalid={
                                        form.errors.first_name
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={
                                        form.errors.first_name
                                            ? 'user-first-name-error'
                                            : undefined
                                    }
                                />
                                <FieldError id="user-first-name-error">
                                    {form.errors.first_name}
                                </FieldError>
                            </Field>
                            <Field
                                data-invalid={
                                    form.errors.last_name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="user_last_name">
                                    {t('common.lastName')}
                                </FieldLabel>
                                <Input
                                    id="user_last_name"
                                    value={form.data.last_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'last_name',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={STRING_LIMITS.NAME}
                                    disabled={readOnly}
                                    aria-invalid={
                                        form.errors.last_name ? true : undefined
                                    }
                                    aria-describedby={
                                        form.errors.last_name
                                            ? 'user-last-name-error'
                                            : undefined
                                    }
                                />
                                <FieldError id="user-last-name-error">
                                    {form.errors.last_name}
                                </FieldError>
                            </Field>
                        </div>

                        <Field
                            data-invalid={form.errors.email ? true : undefined}
                        >
                            <FieldLabel htmlFor="user_email">
                                {t('admin.users.form.emailRequired')}
                            </FieldLabel>
                            <Input
                                id="user_email"
                                type="email"
                                inputMode="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.EMAIL}
                                disabled={readOnly}
                                autoComplete="email"
                                aria-invalid={
                                    form.errors.email ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.email
                                        ? 'user-email-error'
                                        : 'user_email_hint'
                                }
                            />
                            <FieldDescription id="user_email_hint">
                                {t('admin.users.form.emailHint')}{' '}
                                <code>{'name@example.com'}</code>.
                            </FieldDescription>
                            <FieldError id="user-email-error">
                                {form.errors.email}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.password ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="user_password">
                                {editing
                                    ? t('admin.users.form.newPasswordOptional')
                                    : t('admin.users.form.passwordRequired')}
                            </FieldLabel>
                            <PasswordInput
                                id="user_password"
                                value={form.data.password}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                required={isCreate}
                                disabled={readOnly}
                                autoComplete="new-password"
                                aria-invalid={
                                    form.errors.password ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.password
                                        ? 'user-password-error'
                                        : undefined
                                }
                            />
                            <FieldError id="user-password-error">
                                {form.errors.password}
                            </FieldError>
                        </Field>
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onCancel}
                        disabled={form.processing}
                    >
                        {t('common.cancel')}
                    </Button>
                    {readOnly ? null : (
                        <Button type="submit" disabled={form.processing}>
                            {editing ? t('common.save') : t('common.create')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
