<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsInstanceSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateInstanceEmailTemplatesRequest;
use App\Services\EmailTemplateService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Email templates: instance-admin editable copy for verification, password
 * reset and organisation invitations, with locale defaults from lang/{locale}/mail.php.
 */
final class InstanceEmailTemplatesController extends Controller
{
    use LogsInstanceSettingsUpdateTrait;

    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly EmailTemplateService $emailTemplates,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('settings/instance/email-templates', [
            'templates' => $this->emailTemplates->forEdit(),
            'defaults' => $this->emailTemplates->defaultsForEdit(),
            'placeholders' => $this->emailTemplates->placeholdersForEdit(),
            'availableLocales' => config('i18n.available_locales'),
        ]);
    }

    public function update(UpdateInstanceEmailTemplatesRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logInstanceSettingsUpdate($request, 'email-templates', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.email-templates.edit');
    }
}
