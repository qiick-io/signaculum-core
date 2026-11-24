<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\AddOrganizationMemberAction;
use App\Actions\CreateApplicationAction;
use App\Actions\CreateOrganizationAction;
use App\Actions\RegenerateApplicationSecretAction;
use App\Actions\UpdateOrganizationAction;
use App\Actions\UpdateOrganizationBrandingAction;
use App\Data\ApplicationData;
use App\Data\OrganizationData;
use App\Enums\OrganizationRoleEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;

/**
 * Fictional demo data for product screenshots and walkthroughs.
 *
 * Run it on an empty database only: `php artisan migrate:fresh --seeder=DemoSeeder`.
 * Every name, email and URL is made up (`.example` domains, RFC 2606), and every
 * password is `password`, so the data must never be loaded on a reachable instance.
 *
 * The seeder goes through the domain actions and the real auth events rather than
 * raw inserts, so the activity log holds the same entries a real install would.
 */
final class DemoSeeder extends Seeder
{
    public function run(
        CreateOrganizationAction $createOrganization,
        UpdateOrganizationAction $updateOrganization,
        UpdateOrganizationBrandingAction $updateOrganizationBranding,
        AddOrganizationMemberAction $addOrganizationMember,
        CreateApplicationAction $createApplication,
        RegenerateApplicationSecretAction $regenerateApplicationSecret,
    ): void {
        Artisan::call('signaculum:sync-permissions');

        $instanceAdministrator = $this->createDemoUser('Mara', 'Lind', 'mara.lind@acme.example', isInstanceAdmin: true);
        $acmeAdministrator = $this->createDemoUser('Jonas', 'Weber', 'jonas.weber@acme.example');
        $acmeMember = $this->createDemoUser('Priya', 'Nair', 'priya.nair@acme.example');
        $acmeBillingContact = $this->createDemoUser('Tom', 'Becker', 'tom.becker@acme.example');
        $globexOwner = $this->createDemoUser('Elena', 'Moretti', 'elena.moretti@globex.example');
        $globexMember = $this->createDemoUser('Sam', 'Carter', 'sam.carter@globex.example');

        // The account used for the second sign-in step: TOTP confirmed, so the
        // password step leads to the two-factor challenge.
        $this->createDemoUser('Lea', 'Fischer', 'lea.fischer@acme.example')->forceFill([
            'two_factor_secret' => encrypt('DEMOSECRETNOTREAL'),
            'two_factor_recovery_codes' => encrypt(json_encode(['demo-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Sign-ins first, so the newest activity entries are the organisation and
        // application changes the screenshots want on the first page.
        $this->recordSignIns($instanceAdministrator, $acmeAdministrator, $acmeMember, $globexOwner);
        event(new Failed('web', null, ['email' => 'priya.nair@acme.example']));

        $acme = $createOrganization->execute(new OrganizationData('Acme Corp', 'acme'), $instanceAdministrator);
        $updateOrganization->execute($acme, new OrganizationData('Acme Corp', 'acme', isMfaRequired: true), $instanceAdministrator);
        $updateOrganizationBranding->execute(
            $acme,
            ['brand_color' => '#C2410C', 'brand_color_dark' => '#FB923C'],
            ['logo' => $this->demoLogo('#C2410C'), 'logo_dark' => $this->demoLogo('#FB923C')],
            [],
        );

        $addOrganizationMember->execute($acme, $acmeAdministrator, [OrganizationRoleEnum::Admin->value]);
        $addOrganizationMember->execute($acme, $acmeMember, [OrganizationRoleEnum::Member->value]);
        $addOrganizationMember->execute($acme, $acmeBillingContact, [OrganizationRoleEnum::Billing->value, OrganizationRoleEnum::Member->value]);

        $globex = $createOrganization->execute(new OrganizationData('Globex', 'globex'), $globexOwner);
        $addOrganizationMember->execute($globex, $globexMember, [OrganizationRoleEnum::Member->value]);
        $addOrganizationMember->execute($globex, $acmeAdministrator, [OrganizationRoleEnum::Admin->value]);

        $externa = $createApplication->execute(new ApplicationData(
            name: 'Externa',
            redirectUris: ['https://externa.acme.example/auth/oidc/callback'],
            // laravel-oidc's post-logout default, and `{APP_URL}/` for apps on an older beta.
            postLogoutRedirectUris: ['https://externa.acme.example/auth/oidc/logged-out', 'https://externa.acme.example/'],
            isConfidential: true,
            isFirstParty: true,
        ));
        $createApplication->execute(new ApplicationData(
            name: 'Excubia App',
            redirectUris: ['https://excubia.acme.example/auth/oidc/callback', 'https://excubia.globex.example/auth/oidc/callback'],
            postLogoutRedirectUris: [
                'https://excubia.acme.example/auth/oidc/logged-out',
                'https://excubia.acme.example/',
                'https://excubia.globex.example/auth/oidc/logged-out',
                'https://excubia.globex.example/',
            ],
            isConfidential: true,
            isFirstParty: true,
        ));
        $regenerateApplicationSecret->execute($externa, $instanceAdministrator);
    }

    private function createDemoUser(string $firstName, string $lastName, string $email, bool $isInstanceAdmin = false): User
    {
        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ]);

        if ($isInstanceAdmin) {
            $user->assignRole(RoleEnum::SuperAdmin->value);
        }

        return $user;
    }

    /**
     * Dispatch the framework's Login event so the auth listeners write real `login` entries.
     */
    private function recordSignIns(User ...$users): void
    {
        foreach ($users as $user) {
            event(new Login('web', $user, false));
        }
    }

    /**
     * A made-up square logo (rounded tile with an "A"), written to a temporary SVG file.
     */
    private function demoLogo(string $colour): UploadedFile
    {
        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
              <rect width="64" height="64" rx="14" fill="{$colour}"/>
              <path d="M32 14 48 50h-8l-3.5-8h-9L24 50h-8Zm0 13-3 8h6Z" fill="#FFFFFF"/>
            </svg>
            SVG;

        $path = tempnam(sys_get_temp_dir(), 'demo-logo-').'.svg';
        file_put_contents($path, $svg);

        return new UploadedFile($path, 'acme-logo.svg', 'image/svg+xml', null, true);
    }
}
