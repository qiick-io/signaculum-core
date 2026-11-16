import { Form, Head, router } from '@inertiajs/react';
import { KeyRound, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { PageLayout } from '@/components/layout/page-layout';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import {
    destroy as destroyApiKey,
    index as apiKeysIndex,
    store as storeApiKey,
} from '@/routes/admin/api-keys';
import type { BreadcrumbItem } from '@/types';

type ApiKeyRow = {
    uuid: string;
    name: string;
    key_prefix: string;
    scopes: string[];
    is_active: boolean;
    expires_at: string | null;
    last_used_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
};

type Props = {
    apiKeys: ApiKeyRow[];
    availableScopes: string[];
    plainTextSecret?: string | null;
};

/**
 * Admin → API keys: create and revoke Bearer keys for the Admin REST API.
 */
export default function AdminApiKeysIndex({
    apiKeys,
    availableScopes,
    plainTextSecret = null,
}: Props) {
    const { t } = useTranslation();
    const [, copy] = useClipboard();
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [selectedScopes, setSelectedScopes] = useState<string[]>([]);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('admin.apiKeys.title'),
                href: apiKeysIndex.url(),
            },
        ],
        [t],
    );

    const toggleScope = (scope: string, isChecked: boolean) => {
        setSelectedScopes((current) =>
            isChecked
                ? [...current, scope]
                : current.filter((name) => name !== scope),
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('admin.apiKeys.head')} />
            <PageLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <Heading
                            title={t('admin.apiKeys.title')}
                            description={t('admin.apiKeys.description')}
                        />
                        <Button
                            type="button"
                            onClick={() => {
                                setSelectedScopes([]);
                                setIsCreateOpen(true);
                            }}
                        >
                            <Plus />
                            {t('admin.apiKeys.add')}
                        </Button>
                    </div>

                    {plainTextSecret ? (
                        <Alert>
                            <AlertTitle>
                                {t('admin.apiKeys.secretTitle')}
                            </AlertTitle>
                            <AlertDescription className="flex flex-wrap items-center gap-2">
                                <code className="text-sm break-all">
                                    {plainTextSecret}
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => copy(plainTextSecret)}
                                >
                                    {t('common.copy')}
                                </Button>
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {apiKeys.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.apiKeys.empty')}
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {apiKeys.map((apiKey) => (
                                <li
                                    key={apiKey.uuid}
                                    className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-4"
                                >
                                    <div className="space-y-1">
                                        <p className="flex items-center gap-2 font-medium">
                                            <KeyRound className="size-4" />
                                            {apiKey.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {apiKey.key_prefix}…
                                        </p>
                                        <div className="flex flex-wrap gap-1">
                                            {apiKey.scopes.map((scope) => (
                                                <Badge
                                                    key={scope}
                                                    variant="secondary"
                                                >
                                                    {scope}
                                                </Badge>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge
                                            variant={
                                                apiKey.is_active
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {apiKey.is_active
                                                ? t('admin.apiKeys.active')
                                                : t('admin.apiKeys.inactive')}
                                        </Badge>
                                        {apiKey.is_active ? (
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        destroyApiKey.url(
                                                            apiKey.uuid,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                                <span className="sr-only">
                                                    {t('admin.apiKeys.revoke')}
                                                </span>
                                            </Button>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {t('admin.apiKeys.createTitle')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('admin.apiKeys.formDescription')}
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            action={storeApiKey.url()}
                            method="post"
                            options={{ preserveScroll: true }}
                            onSuccess={() => setIsCreateOpen(false)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <FieldGroup>
                                        <Field>
                                            <FieldLabel htmlFor="api-key-name">
                                                {t('common.name')}
                                            </FieldLabel>
                                            <Input
                                                id="api-key-name"
                                                name="name"
                                                required
                                            />
                                            <FieldError>
                                                {errors.name}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel>
                                                {t('admin.apiKeys.scopes')}
                                            </FieldLabel>
                                            <div className="space-y-2">
                                                {availableScopes.map(
                                                    (scope) => (
                                                        <label
                                                            key={scope}
                                                            className="flex items-center gap-2 text-sm"
                                                        >
                                                            <Checkbox
                                                                checked={selectedScopes.includes(
                                                                    scope,
                                                                )}
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    toggleScope(
                                                                        scope,
                                                                        checked ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                            <span>{scope}</span>
                                                            {selectedScopes.includes(
                                                                scope,
                                                            ) ? (
                                                                <input
                                                                    type="hidden"
                                                                    name="scopes[]"
                                                                    value={
                                                                        scope
                                                                    }
                                                                />
                                                            ) : null}
                                                        </label>
                                                    ),
                                                )}
                                            </div>
                                            <FieldError>
                                                {errors.scopes}
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setIsCreateOpen(false)
                                            }
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                selectedScopes.length === 0
                                            }
                                        >
                                            {t('admin.apiKeys.add')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
