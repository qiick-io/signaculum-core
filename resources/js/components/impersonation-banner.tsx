import { router, usePage } from '@inertiajs/react';
import { UserRoundSearch } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { destroy } from '@/routes/impersonation';

/**
 * Persistent banner while an instance admin is viewing the app as another user.
 * Stop restores the actor session (DELETE /impersonation).
 */
export function ImpersonationBanner() {
    const { t } = useTranslation();
    const impersonation = usePage().props.auth.impersonation;

    if (!impersonation) {
        return null;
    }

    return (
        <div className="shrink-0 border-b border-warning/40 bg-warning/10 px-4 py-2">
            <Alert variant="warning" className="border-0 bg-transparent p-0">
                <UserRoundSearch />
                <AlertTitle>{t('impersonation.bannerTitle')}</AlertTitle>
                <AlertDescription className="flex flex-wrap items-center gap-3">
                    <span>
                        {t('impersonation.bannerDescription', {
                            name: impersonation.actor_name,
                        })}
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                            router.delete(destroy.url(), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        {t('impersonation.stop')}
                    </Button>
                </AlertDescription>
            </Alert>
        </div>
    );
}
