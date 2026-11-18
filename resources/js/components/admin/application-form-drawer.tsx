import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
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
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { STRING_LIMITS } from '@/lib/string-limits';
import {
    destroy as destroyApplication,
    store as storeApplication,
    update as updateApplication,
} from '@/routes/admin/applications';
import { update as updateApplicationSecret } from '@/routes/admin/applications/secret';
import type { AdminApplicationRow } from '@/types/admin';

const EMPTY_APPLICATION_FORM = {
    name: '',
    redirect_uris: '',
    is_confidential: true,
    is_first_party: false,
    is_client_credentials_enabled: false,
    is_device_authorization_enabled: false,
    is_pushed_authorization_required: false,
};

export type ApplicationFormDrawerProps = {
    editing: AdminApplicationRow | null;
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing OIDC applications (app-ui `forms.md`).
 * Shows client id / discovery / oidc:configure when editing; secret regenerate
 * and typed delete use confirm dialogs (app-ui `destructive-actions.md`).
 */
export function ApplicationFormDrawer({
    editing,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: ApplicationFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_APPLICATION_FORM });
    const isCreate = editing === null;
    const [, copy] = useClipboard();
    const [isRegenerateOpen, setIsRegenerateOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isBusy, setIsBusy] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload = {
                name: editing.name,
                redirect_uris: editing.redirect_uris.join('\n'),
                is_confidential: editing.is_confidential,
                is_first_party: editing.is_first_party,
                is_client_credentials_enabled:
                    editing.is_client_credentials_enabled,
                is_device_authorization_enabled:
                    editing.is_device_authorization_enabled,
                is_pushed_authorization_required:
                    editing.is_pushed_authorization_required,
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_APPLICATION_FORM });
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
            form.put(updateApplication.url(editing.id), opts);
        } else {
            form.post(storeApplication.url(), opts);
        }
    };

    const regenerateSecret = (): void => {
        if (!editing) {
            return;
        }

        setIsBusy(true);
        router.put(
            updateApplicationSecret.url(editing.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsBusy(false);
                    setIsRegenerateOpen(false);
                },
                onSuccess: () => {
                    onSuccess?.();
                },
            },
        );
    };

    const deleteApplication = (): void => {
        if (!editing) {
            return;
        }

        setIsBusy(true);
        router.delete(destroyApplication.url(editing.id), {
            data: { confirmation: editing.name },
            preserveScroll: true,
            onFinish: () => {
                setIsBusy(false);
                setIsDeleteOpen(false);
            },
            onSuccess: () => {
                onSuccess?.();
            },
        });
    };

    const title = editing
        ? t('admin.applications.form.editTitle')
        : t('admin.applications.newApplication');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {editing
                        ? t('admin.applications.form.editDescription')
                        : t('admin.applications.form.createDescription')}
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
                <DrawerBody className="overflow-y-auto">
                    <FieldGroup>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.applications.form.requiredHint')}
                        </p>

                        {editing ? (
                            <>
                                <Field>
                                    <FieldLabel htmlFor="application-client-id">
                                        {t('admin.applications.form.clientId')}
                                    </FieldLabel>
                                    <div className="flex gap-2">
                                        <Input
                                            id="application-client-id"
                                            value={editing.id}
                                            readOnly
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => copy(editing.id)}
                                        >
                                            {t('common.copy')}
                                        </Button>
                                    </div>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="application-discovery">
                                        {t(
                                            'admin.applications.form.discoveryUrl',
                                        )}
                                    </FieldLabel>
                                    <div className="flex gap-2">
                                        <Input
                                            id="application-discovery"
                                            value={editing.discovery_url}
                                            readOnly
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                copy(editing.discovery_url)
                                            }
                                        >
                                            {t('common.copy')}
                                        </Button>
                                    </div>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="application-configure">
                                        {t(
                                            'admin.applications.form.configureCommand',
                                        )}
                                    </FieldLabel>
                                    <div className="flex gap-2">
                                        <Input
                                            id="application-configure"
                                            value={editing.configure_command}
                                            readOnly
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                copy(editing.configure_command)
                                            }
                                        >
                                            {t('common.copy')}
                                        </Button>
                                    </div>
                                </Field>
                            </>
                        ) : null}

                        <Field data-invalid={!!form.errors.name || undefined}>
                            <FieldLabel htmlFor="application-name">
                                {t('admin.applications.form.nameRequired')}
                            </FieldLabel>
                            <Input
                                id="application-name"
                                name="name"
                                value={form.data.name}
                                maxLength={STRING_LIMITS.NAME}
                                disabled={readOnly || form.processing}
                                aria-invalid={!!form.errors.name || undefined}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                !!form.errors.redirect_uris || undefined
                            }
                        >
                            <FieldLabel htmlFor="application-redirect-uris">
                                {t(
                                    'admin.applications.form.redirectUrisRequired',
                                )}
                            </FieldLabel>
                            <Textarea
                                id="application-redirect-uris"
                                name="redirect_uris"
                                value={form.data.redirect_uris}
                                rows={4}
                                disabled={readOnly || form.processing}
                                aria-invalid={
                                    !!form.errors.redirect_uris || undefined
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'redirect_uris',
                                        event.target.value,
                                    )
                                }
                            />
                            <FieldDescription>
                                {t('admin.applications.form.redirectUrisHint')}
                            </FieldDescription>
                            <FieldError>
                                {form.errors.redirect_uris}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="application-confidential"
                                    checked={form.data.is_confidential}
                                    disabled={readOnly || form.processing}
                                    onCheckedChange={(checked) => {
                                        const isConfidential = checked === true;

                                        form.setData(
                                            'is_confidential',
                                            isConfidential,
                                        );

                                        if (!isConfidential) {
                                            form.setData(
                                                'is_client_credentials_enabled',
                                                false,
                                            );
                                        }
                                    }}
                                />
                                <div className="grid gap-1.5 leading-none">
                                    <FieldLabel htmlFor="application-confidential">
                                        {t(
                                            'admin.applications.form.confidential',
                                        )}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'admin.applications.form.confidentialHint',
                                        )}
                                    </FieldDescription>
                                </div>
                            </div>
                            <FieldError>
                                {form.errors.is_confidential}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="application-first-party"
                                    checked={form.data.is_first_party}
                                    disabled={readOnly || form.processing}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_first_party',
                                            checked === true,
                                        )
                                    }
                                />
                                <div className="grid gap-1.5 leading-none">
                                    <FieldLabel htmlFor="application-first-party">
                                        {t(
                                            'admin.applications.form.firstParty',
                                        )}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'admin.applications.form.firstPartyHint',
                                        )}
                                    </FieldDescription>
                                </div>
                            </div>
                            <FieldError>
                                {form.errors.is_first_party}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="application-client-credentials"
                                    checked={
                                        form.data.is_client_credentials_enabled
                                    }
                                    disabled={
                                        readOnly ||
                                        form.processing ||
                                        !form.data.is_confidential
                                    }
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_client_credentials_enabled',
                                            checked === true,
                                        )
                                    }
                                />
                                <div className="grid gap-1.5 leading-none">
                                    <FieldLabel htmlFor="application-client-credentials">
                                        {t(
                                            'admin.applications.form.clientCredentials',
                                        )}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'admin.applications.form.clientCredentialsHint',
                                        )}
                                    </FieldDescription>
                                </div>
                            </div>
                            <FieldError>
                                {form.errors.is_client_credentials_enabled}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="application-device-authorization"
                                    checked={
                                        form.data
                                            .is_device_authorization_enabled
                                    }
                                    disabled={readOnly || form.processing}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_device_authorization_enabled',
                                            checked === true,
                                        )
                                    }
                                />
                                <div className="grid gap-1.5 leading-none">
                                    <FieldLabel htmlFor="application-device-authorization">
                                        {t(
                                            'admin.applications.form.deviceAuthorization',
                                        )}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'admin.applications.form.deviceAuthorizationHint',
                                        )}
                                    </FieldDescription>
                                </div>
                            </div>
                            <FieldError>
                                {form.errors.is_device_authorization_enabled}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="application-pushed-authorization"
                                    checked={
                                        form.data
                                            .is_pushed_authorization_required
                                    }
                                    disabled={readOnly || form.processing}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_pushed_authorization_required',
                                            checked === true,
                                        )
                                    }
                                />
                                <div className="grid gap-1.5 leading-none">
                                    <FieldLabel htmlFor="application-pushed-authorization">
                                        {t(
                                            'admin.applications.form.pushedAuthorization',
                                        )}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t(
                                            'admin.applications.form.pushedAuthorizationHint',
                                        )}
                                    </FieldDescription>
                                </div>
                            </div>
                            <FieldError>
                                {form.errors.is_pushed_authorization_required}
                            </FieldError>
                        </Field>

                        {editing && !readOnly ? (
                            <div className="flex flex-wrap gap-2 pt-2">
                                {editing.is_confidential ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={isBusy}
                                        onClick={() =>
                                            setIsRegenerateOpen(true)
                                        }
                                    >
                                        {t(
                                            'admin.applications.actions.regenerateSecret',
                                        )}
                                    </Button>
                                ) : null}
                                {editing.can_delete ? (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        disabled={isBusy}
                                        onClick={() => setIsDeleteOpen(true)}
                                    >
                                        {t(
                                            'admin.applications.actions.delete',
                                        )}
                                    </Button>
                                ) : null}
                            </div>
                        ) : null}
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing || isBusy}
                        onClick={onCancel}
                    >
                        {t('common.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button
                            type="submit"
                            disabled={form.processing || isBusy}
                        >
                            {isCreate ? t('common.create') : t('common.save')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>

            <ConfirmDestructiveDialog
                open={isRegenerateOpen}
                onOpenChange={setIsRegenerateOpen}
                title={t('admin.applications.confirm.regenerateTitle')}
                description={t(
                    'admin.applications.confirm.regenerateDescription',
                )}
                confirmLabel={t(
                    'admin.applications.actions.regenerateSecret',
                )}
                confirming={isBusy}
                onConfirm={regenerateSecret}
            />

            <ConfirmDestructiveDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                title={t('admin.applications.confirm.deleteTitle')}
                description={t('admin.applications.confirm.deleteDescription')}
                confirmLabel={t('admin.applications.actions.delete')}
                confirming={isBusy}
                typedConfirmation={editing?.name}
                onConfirm={deleteApplication}
            />
        </DrawerContent>
    );
}
