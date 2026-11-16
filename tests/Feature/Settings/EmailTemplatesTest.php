<?php

declare(strict_types=1);

use App\Enums\EmailTemplateTypeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\EmailTemplateService;
use App\Services\SettingsRepositoryService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Instance-admin editable transactional email templates (#53): defaults, fallbacks,
 * settings page, and use by verification / reset / invitation notifications.
 */
function emailTemplatesAdmin(): User
{
    return User::factory()->create(['is_instance_admin' => true]);
}

it('exposes branded default templates for every type and locale', function () {
    $service = app(EmailTemplateService::class);

    foreach (EmailTemplateTypeEnum::cases() as $type) {
        foreach (['en', 'it', 'de'] as $locale) {
            $template = $service->defaultFor($type, $locale);

            expect($template->subject)->not->toBeEmpty()
                ->and($template->body)->toContain('{{app_name}}');

            if ($type !== EmailTemplateTypeEnum::EmailLoginCode) {
                expect($template->actionLabel)->not->toBeEmpty();
            }
        }
    }

    $englishVerification = $service->defaultFor(EmailTemplateTypeEnum::Verification, 'en');

    expect($englishVerification->subject)->toBe('Verify your email address')
        ->and($englishVerification->body)->toContain('Signaculum');
});

it('falls back to English defaults for an unknown locale', function () {
    $template = app(EmailTemplateService::class)
        ->resolve(EmailTemplateTypeEnum::Reset, 'fr');

    expect($template->subject)->toBe(
        app(EmailTemplateService::class)->defaultFor(EmailTemplateTypeEnum::Reset, 'en')->subject
    );
});

it('uses saved overrides and falls back field-by-field to defaults', function () {
    app(SettingsRepositoryService::class)->save([
        'email_templates' => [
            'verification' => [
                'en' => [
                    'subject' => 'Confirm your Signaculum account',
                    'body' => '',
                    'action_label' => '',
                ],
            ],
        ],
    ]);

    $service = app(EmailTemplateService::class);
    $resolved = $service->resolve(EmailTemplateTypeEnum::Verification, 'en');
    $default = $service->defaultFor(EmailTemplateTypeEnum::Verification, 'en');

    expect($resolved->subject)->toBe('Confirm your Signaculum account')
        ->and($resolved->body)->toBe($default->body)
        ->and($resolved->actionLabel)->toBe($default->actionLabel);
});

it('builds a mail message from the resolved template and placeholders', function () {
    $message = app(EmailTemplateService::class)->mailMessage(
        EmailTemplateTypeEnum::Invitation,
        'en',
        [
            'app_name' => 'Signaculum',
            'organization_name' => 'Acme',
            'url' => 'https://example.test/invite',
        ],
    );

    expect($message->subject)->toBeString()->and($message->subject)->toContain('Acme');

    $rendered = (string) $message->render();

    expect($rendered)->toContain('Acme')
        ->and($rendered)->toContain('https://example.test/invite');
});

it('shows the email templates page to instance admins with defaults', function () {
    $this->actingAs(emailTemplatesAdmin())
        ->get(route('instance.email-templates.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/email-templates')
            ->has('templates.verification.en.subject')
            ->has('defaults.verification.en.subject')
            ->has('placeholders.verification')
            ->has('availableLocales'));
});

it('keeps the email templates page from users who are not instance admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('instance.email-templates.edit'))
        ->assertForbidden();
});

it('saves email template overrides', function () {
    $this->actingAs(emailTemplatesAdmin())
        ->put(route('instance.email-templates.update'), [
            'templates' => [
                'verification' => [
                    'en' => [
                        'subject' => 'Please verify',
                        'body' => "Hello {{user_name}},\n\nConfirm on {{app_name}}.",
                        'action_label' => 'Verify now',
                    ],
                    'it' => [
                        'subject' => '',
                        'body' => '',
                        'action_label' => '',
                    ],
                    'de' => [
                        'subject' => '',
                        'body' => '',
                        'action_label' => '',
                    ],
                ],
                'reset' => [
                    'en' => ['subject' => '', 'body' => '', 'action_label' => ''],
                    'it' => ['subject' => '', 'body' => '', 'action_label' => ''],
                    'de' => ['subject' => '', 'body' => '', 'action_label' => ''],
                ],
                'invitation' => [
                    'en' => ['subject' => '', 'body' => '', 'action_label' => ''],
                    'it' => ['subject' => '', 'body' => '', 'action_label' => ''],
                    'de' => ['subject' => '', 'body' => '', 'action_label' => ''],
                ],
                'email_login_code' => [
                    'en' => ['subject' => '', 'body' => '', 'action_label' => ''],
                    'it' => ['subject' => '', 'body' => '', 'action_label' => ''],
                    'de' => ['subject' => '', 'body' => '', 'action_label' => ''],
                ],
            ],
        ])
        ->assertRedirect(route('instance.email-templates.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $resolved = app(EmailTemplateService::class)
        ->resolve(EmailTemplateTypeEnum::Verification, 'en');

    expect($resolved->subject)->toBe('Please verify')
        ->and($resolved->actionLabel)->toBe('Verify now');
});

it('uses the custom verification template in the VerifyEmail notification', function () {
    Notification::fake();

    app(SettingsRepositoryService::class)->save([
        'email_templates' => [
            'verification' => [
                'en' => [
                    'subject' => 'Custom verify subject',
                    'body' => 'Custom verify body for {{app_name}}.',
                    'action_label' => 'Custom verify',
                ],
            ],
        ],
    ]);

    $user = User::factory()->unverified()->create(['locale' => 'en']);

    $this->actingAs($user)->post(route('verification.send'));

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        return $mail->subject === 'Custom verify subject'
            && str_contains($html, 'Custom verify body')
            && str_contains($html, 'Custom verify');
    });
});

it('uses the custom reset template in the ResetPassword notification', function () {
    Notification::fake();

    app(SettingsRepositoryService::class)->save([
        'email_templates' => [
            'reset' => [
                'en' => [
                    'subject' => 'Custom reset subject',
                    'body' => 'Custom reset body expires in {{expire_minutes}} minutes.',
                    'action_label' => 'Custom reset',
                ],
            ],
        ],
    ]);

    $user = User::factory()->create(['locale' => 'en']);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        return $mail->subject === 'Custom reset subject'
            && str_contains($html, 'Custom reset body')
            && str_contains($html, 'Custom reset');
    });
});

it('uses the custom invitation template', function () {
    Notification::fake();

    app(SettingsRepositoryService::class)->save([
        'email_templates' => [
            'invitation' => [
                'en' => [
                    'subject' => 'Join {{organization_name}}',
                    'body' => 'You are invited to {{organization_name}} on {{app_name}}.',
                    'action_label' => 'Accept now',
                ],
            ],
        ],
    ]);

    $owner = User::factory()->create();
    $organization = Organization::factory()->create(['name' => 'Acme Org']);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $owner->getKey(),
        'roles' => [OrganizationRoleEnum::Owner->value],
    ]);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'invitee@example.com',
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect(route('organizations.invitations.index', $organization));

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification, array $channels, object $notifiable) {
            $mail = $notification->toMail($notifiable);
            $html = (string) $mail->render();

            return $mail->subject === 'Join Acme Org'
                && str_contains($html, 'Accept now')
                && str_contains($html, 'Acme Org');
        }
    );
});
