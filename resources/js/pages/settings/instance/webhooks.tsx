import { Form, Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
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
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    destroy as destroyWebhook,
    index as webhooksIndex,
    store as storeWebhook,
    update as updateWebhook,
} from '@/routes/instance/webhooks';
import { update as updatePrivateUrls } from '@/routes/instance/webhooks/private-urls';
import type { BreadcrumbItem } from '@/types';

type WebhookDeliveryRow = {
    event_id: string;
    event_type: string;
    status: string;
    http_status: number | null;
    attempt: number;
    created_at: string | null;
};

type WebhookRow = {
    uuid: string;
    url: string;
    events: string[];
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
    recent_deliveries: WebhookDeliveryRow[];
};

type Props = {
    webhooks: { data: WebhookRow[] } | WebhookRow[];
    availableEvents: string[];
    settings: { webhooks_allow_private_urls: boolean };
    plainTextSecret?: string | null;
};

function normalizeWebhooks(webhooks: Props['webhooks']): WebhookRow[] {
    if (Array.isArray(webhooks)) {
        return webhooks;
    }

    return webhooks.data ?? [];
}

/**
 * Settings → Webhooks: register outbound event endpoints, signing secrets,
 * and the private-URL SSRF opt-in for local QA.
 */
export default function InstanceWebhooksPage({
    webhooks: webhooksProp,
    availableEvents,
    settings,
    plainTextSecret = null,
}: Props) {
    const { t } = useTranslation();
    const webhooks = normalizeWebhooks(webhooksProp);
    const [, copy] = useClipboard();
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editing, setEditing] = useState<WebhookRow | null>(null);
    const [selectedEvents, setSelectedEvents] = useState<string[]>([]);
    const [isActive, setIsActive] = useState(true);
    const [allowPrivateUrls, setAllowPrivateUrls] = useState(
        settings.webhooks_allow_private_urls,
    );

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('settings.layout.webhooks'),
                href: webhooksIndex.url(),
            },
        ],
        [t],
    );

    const openCreate = () => {
        setEditing(null);
        setSelectedEvents([]);
        setIsActive(true);
        setIsCreateOpen(true);
    };

    const openEdit = (webhook: WebhookRow) => {
        setEditing(webhook);
        setSelectedEvents([...webhook.events]);
        setIsActive(webhook.is_active);
        setIsCreateOpen(true);
    };

    const toggleEvent = (eventName: string, isChecked: boolean) => {
        setSelectedEvents((current) =>
            isChecked
                ? [...current, eventName]
                : current.filter((name) => name !== eventName),
        );
    };

    const savePrivateUrls = (isAllowed: boolean) => {
        setAllowPrivateUrls(isAllowed);
        router.put(updatePrivateUrls.url(), {
            webhooks_allow_private_urls: isAllowed,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.webhooks.head')} />
            <SettingsLayout wide>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <Heading
                            title={t('settings.webhooks.title')}
                            description={t('settings.webhooks.description')}
                        />
                        <Button type="button" onClick={openCreate}>
                            <Plus />
                            {t('settings.webhooks.add')}
                        </Button>
                    </div>

                    {plainTextSecret ? (
                        <Alert>
                            <AlertTitle>
                                {t('settings.webhooks.secretTitle')}
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

                    <div className="flex items-center justify-between gap-4 rounded-lg border border-border p-4">
                        <div className="space-y-1">
                            <Label htmlFor="allow-private-urls">
                                {t('settings.webhooks.allowPrivateUrls')}
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'settings.webhooks.allowPrivateUrlsDescription',
                                )}
                            </p>
                        </div>
                        <Switch
                            id="allow-private-urls"
                            checked={allowPrivateUrls}
                            onCheckedChange={savePrivateUrls}
                        />
                    </div>

                    {webhooks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('settings.webhooks.empty')}
                        </p>
                    ) : (
                        <ul className="space-y-4">
                            {webhooks.map((webhook) => (
                                <li
                                    key={webhook.uuid}
                                    className="space-y-3 rounded-lg border border-border p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="space-y-1">
                                            <p className="font-medium break-all">
                                                {webhook.url}
                                            </p>
                                            <div className="flex flex-wrap gap-1">
                                                {webhook.events.map(
                                                    (eventName) => (
                                                        <Badge
                                                            key={eventName}
                                                            variant="secondary"
                                                        >
                                                            {eventName}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={
                                                    webhook.is_active
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {webhook.is_active
                                                    ? t(
                                                          'settings.webhooks.active',
                                                      )
                                                    : t(
                                                          'settings.webhooks.inactive',
                                                      )}
                                            </Badge>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    openEdit(webhook)
                                                }
                                            >
                                                {t('common.edit')}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        destroyWebhook.url(
                                                            webhook.uuid,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                                <span className="sr-only">
                                                    {t('common.delete')}
                                                </span>
                                            </Button>
                                        </div>
                                    </div>
                                    {webhook.recent_deliveries.length > 0 ? (
                                        <div className="space-y-1">
                                            <p className="text-sm font-medium">
                                                {t(
                                                    'settings.webhooks.recentDeliveries',
                                                )}
                                            </p>
                                            <ul className="space-y-1 text-sm text-muted-foreground">
                                                {webhook.recent_deliveries.map(
                                                    (delivery) => (
                                                        <li
                                                            key={`${delivery.event_id}-${delivery.attempt}`}
                                                        >
                                                            {
                                                                delivery.event_type
                                                            }{' '}
                                                            · {delivery.status}
                                                            {delivery.http_status
                                                                ? ` · HTTP ${delivery.http_status}`
                                                                : ''}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <Dialog
                    open={isCreateOpen}
                    onOpenChange={(isOpen) => {
                        setIsCreateOpen(isOpen);
                        if (!isOpen) {
                            setEditing(null);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {editing
                                    ? t('settings.webhooks.editTitle')
                                    : t('settings.webhooks.createTitle')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('settings.webhooks.formDescription')}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            action={
                                editing
                                    ? updateWebhook.url(editing.uuid)
                                    : storeWebhook.url()
                            }
                            method={editing ? 'put' : 'post'}
                            options={{ preserveScroll: true }}
                            onSuccess={() => {
                                setIsCreateOpen(false);
                                setEditing(null);
                            }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <FieldGroup>
                                        <Field>
                                            <FieldLabel htmlFor="webhook-url">
                                                {t('settings.webhooks.url')}
                                            </FieldLabel>
                                            <Input
                                                id="webhook-url"
                                                name="url"
                                                type="url"
                                                required
                                                defaultValue={
                                                    editing?.url ?? ''
                                                }
                                                placeholder="https://example.com/hooks/signaculum"
                                            />
                                            <FieldDescription>
                                                {t(
                                                    'settings.webhooks.urlDescription',
                                                )}
                                            </FieldDescription>
                                            <FieldError>
                                                {errors.url}
                                            </FieldError>
                                        </Field>

                                        <Field>
                                            <FieldLabel>
                                                {t('settings.webhooks.events')}
                                            </FieldLabel>
                                            <div className="space-y-2">
                                                {availableEvents.map(
                                                    (eventName) => (
                                                        <label
                                                            key={eventName}
                                                            className="flex items-center gap-2 text-sm"
                                                        >
                                                            <Checkbox
                                                                checked={selectedEvents.includes(
                                                                    eventName,
                                                                )}
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    toggleEvent(
                                                                        eventName,
                                                                        checked ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                            <span>
                                                                {eventName}
                                                            </span>
                                                            {selectedEvents.includes(
                                                                eventName,
                                                            ) ? (
                                                                <input
                                                                    type="hidden"
                                                                    name="events[]"
                                                                    value={
                                                                        eventName
                                                                    }
                                                                />
                                                            ) : null}
                                                        </label>
                                                    ),
                                                )}
                                            </div>
                                            <FieldError>
                                                {errors.events}
                                            </FieldError>
                                        </Field>

                                        <Field>
                                            <label className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={isActive}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setIsActive(
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                <span>
                                                    {t(
                                                        'settings.webhooks.active',
                                                    )}
                                                </span>
                                            </label>
                                            <input
                                                type="hidden"
                                                name="is_active"
                                                value={isActive ? '1' : '0'}
                                            />
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
                                                selectedEvents.length === 0
                                            }
                                        >
                                            {editing
                                                ? t('common.save')
                                                : t('settings.webhooks.add')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
