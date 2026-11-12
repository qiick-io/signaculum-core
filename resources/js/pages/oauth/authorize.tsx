import { Head } from '@inertiajs/react';
import i18n from 'i18next';
import { Check } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

type ConsentScope = {
    id: string;
    description: string;
};

type AuthorizeProps = {
    clientName: string;
    scopes: ConsentScope[];
    authToken: string;
    csrfToken: string;
};

/**
 * OAuth consent screen (Passport `authorizationView`).
 *
 * Approve and deny are plain HTML form posts, not Inertia visits: Passport answers
 * with a redirect to the client's redirect URI on another origin, which an XHR visit
 * cannot follow.
 */
export default function Authorize({
    clientName,
    scopes,
    authToken,
    csrfToken,
}: AuthorizeProps) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('oauth.authorize.title')} />

            <div className="space-y-6">
                <p className="text-center text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {clientName}
                    </span>{' '}
                    {t('oauth.authorize.requestingAccess')}
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
                        action="/oauth/authorize"
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
                            data-test="deny-authorization"
                        >
                            {t('common.cancel')}
                        </Button>
                    </form>

                    <form
                        method="post"
                        action="/oauth/authorize"
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
                            data-test="approve-authorization"
                        >
                            {t('oauth.authorize.approve')}
                        </Button>
                    </form>
                </div>
            </div>
        </>
    );
}

Authorize.layout = {
    // Read through i18n at render time (AuthLayout props), after `initI18n` in app.tsx.
    get title(): string {
        return i18n.t('oauth.authorize.title');
    },
    get description(): string {
        return i18n.t('oauth.authorize.description');
    },
};
