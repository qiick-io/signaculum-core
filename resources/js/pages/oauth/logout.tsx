import { Form, Head } from '@inertiajs/react';
import i18n from 'i18next';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

type LogoutProps = {
    clientId: string | null;
    postLogoutRedirectUri: string | null;
    state: string | null;
    idTokenHint: string | null;
};

/**
 * Confirmation step when RP-initiated logout has no matching `id_token_hint`
 * (OIDC RP-Initiated Logout 1.0 §2). Plain form post so an external redirect works.
 */
export default function Logout({
    clientId,
    postLogoutRedirectUri,
    state,
    idTokenHint,
}: LogoutProps) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('oauth.logout.title')} />

            <div className="space-y-6">
                <p className="text-center text-sm text-muted-foreground">
                    {t('oauth.logout.description')}
                </p>

                <Form
                    action="/oauth/end-session/confirm"
                    method="post"
                    className="flex flex-col gap-3"
                >
                    {({ processing }) => (
                        <>
                            {clientId ? (
                                <input
                                    type="hidden"
                                    name="client_id"
                                    value={clientId}
                                />
                            ) : null}
                            {postLogoutRedirectUri ? (
                                <input
                                    type="hidden"
                                    name="post_logout_redirect_uri"
                                    value={postLogoutRedirectUri}
                                />
                            ) : null}
                            {state ? (
                                <input
                                    type="hidden"
                                    name="state"
                                    value={state}
                                />
                            ) : null}
                            {idTokenHint ? (
                                <input
                                    type="hidden"
                                    name="id_token_hint"
                                    value={idTokenHint}
                                />
                            ) : null}

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {t('oauth.logout.confirm')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Logout.layout = {
    get title(): string {
        return i18n.t('oauth.logout.title');
    },
    get description(): string {
        return i18n.t('oauth.logout.description');
    },
};
