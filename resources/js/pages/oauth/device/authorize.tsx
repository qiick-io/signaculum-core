import { Head } from '@inertiajs/react';
import i18n from 'i18next';
import { Check } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

type ConsentScope = {
    id: string;
    description: string;
};

type DeviceAuthorizeProps = {
    clientName: string;
    scopes: ConsentScope[];
    authToken: string;
    csrfToken: string;
};

/**
 * RFC 8628 device consent (Passport device authorization view).
 * Approve/deny are plain HTML posts so Passport can finish the device code.
 */
export default function DeviceAuthorize({
    clientName,
    scopes,
    authToken,
    csrfToken,
}: DeviceAuthorizeProps) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('oauth.device.authorizeTitle')} />

            <div className="space-y-6">
                <p className="text-center text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {clientName}
                    </span>{' '}
                    {t('oauth.device.requestingAccess')}
                </p>

                {scopes.length > 0 ? (
                    <ul
                        className="space-y-2 text-sm"
                        aria-label={t('oauth.authorize.permissionsLabel')}
                    >
                        {scopes.map((scope) => (
                            <li key={scope.id} className="flex gap-2">
                                <Check
                                    className="mt-0.5 size-4 shrink-0 text-primary"
                                    aria-hidden
                                />
                                <span>{scope.description}</span>
                            </li>
                        ))}
                    </ul>
                ) : null}

                <div className="flex gap-2">
                    <form
                        method="post"
                        action="/oauth/device/authorize"
                        className="flex-1"
                    >
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input type="hidden" name="_method" value="DELETE" />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            className="w-full"
                            data-test="deny-device-authorization"
                        >
                            {t('common.cancel')}
                        </Button>
                    </form>

                    <form
                        method="post"
                        action="/oauth/device/authorize"
                        className="flex-1"
                    >
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button
                            type="submit"
                            className="w-full"
                            data-test="approve-device-authorization"
                        >
                            {t('oauth.authorize.approve')}
                        </Button>
                    </form>
                </div>
            </div>
        </>
    );
}

DeviceAuthorize.layout = {
    get title(): string {
        return i18n.t('oauth.device.authorizeTitle');
    },
    get description(): string {
        return i18n.t('oauth.device.authorizeDescription');
    },
};
