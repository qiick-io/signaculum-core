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
import { Textarea } from '@/components/ui/textarea';
import { STRING_LIMITS } from '@/lib/string-limits';
import {
    store as storeApplication,
    update as updateApplication,
} from '@/routes/admin/applications';
import type { AdminApplicationRow } from '@/types/admin';

const EMPTY_APPLICATION_FORM = {
    name: '',
    redirect_uris: '',
    is_confidential: true,
    is_first_party: false,
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
 * Secret regenerate/delete and strict URI rules are sibling issues.
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
                                {t(
                                    'admin.applications.form.redirectUrisHint',
                                )}
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
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_confidential',
                                            checked === true,
                                        )
                                    }
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
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={onCancel}
                    >
                        {t('common.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {isCreate ? t('common.create') : t('common.save')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
