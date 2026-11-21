<?php

declare(strict_types=1);

use App\Data\LdapSettingsData;
use App\Enums\ApiErrorCodeEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\LdapEncryptionEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\OrganizationMembership;
use App\Models\SigningKey;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\InitialSuperAdminPasswordService;
use App\Services\LdapSettingsService;
use App\Services\SigningKeyService;
use App\Services\WebhookUrlGuardService;
use Database\Seeders\CreateSuperAdminSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Encryption\Encrypter as FrameworkEncrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Spatie\Permission\Models\Role;

/**
 * Product-owned behaviour hooks for the fleet conformance suite.
 *
 * @return array<string, callable(): void>
 */
return [
    'assertSoftDeletedGroupGrantsNothing' => static function (): void {
        syncPermissions();

        $user = User::factory()->create();
        $group = UserGroup::query()->create([
            'name' => 'conformance-deleted-group-'.uniqid(),
            'description' => null,
        ]);
        $readerRole = Role::findByName(RoleEnum::Reader->value, 'web');
        $group->roles()->attach($readerRole->getKey());
        $group->users()->attach($user->getKey());

        $resolver = app(EffectivePermissionResolverService::class);
        $permission = PermissionEnum::CanShowUsers->value;

        if (! $resolver->hasPermission($user, $permission)) {
            throw new RuntimeException('Expected the reader group role to grant can-show-users before soft-delete.');
        }

        $group->delete();
        $resolver->forget($user);
        $user->unsetRelation('groups');
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        if ($resolver->hasPermission($user->fresh(), $permission)) {
            throw new RuntimeException('Soft-deleted group still grants permissions through the resolver.');
        }
    },

    'assertGlobalRoleGuard' => static function (): void {
        syncPermissions();

        $roleTable = config('permission.table_names.roles', 'roles');
        $guard = 'web';
        $name = RoleEnum::Admin->value;

        if (DB::table($roleTable)->where('name', $name)->where('guard_name', $guard)->count() !== 1) {
            throw new RuntimeException("Expected exactly one {$name} role before the duplicate probe.");
        }

        test()->artisan('signaculum:sync-permissions')->assertSuccessful();

        if (DB::table($roleTable)->where('name', $name)->where('guard_name', $guard)->count() !== 1) {
            throw new RuntimeException('Global role definitions must not duplicate via sync-permissions.');
        }
    },

    'assertApiKeyActivityUuid' => static function (): void {
        $created = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
        $target = User::factory()->create(['first_name' => 'Before']);

        $request = Request::create('/api/v1/users/'.$target->subject, 'PATCH', [
            'first_name' => 'After',
            'email' => $target->email,
        ]);
        $request->headers->set('Authorization', 'Bearer '.$created['plain']);
        $request->headers->set('Accept', 'application/json');

        $response = app(HttpKernel::class)->handle($request);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('API-key write for C13 failed: '.$response->getContent());
        }

        $activity = DB::table('activity_log')
            ->where('subject_type', User::class)
            ->where(function ($query) use ($target): void {
                $query->where('subject_id', (string) $target->getKey())
                    ->orWhere('subject_id', $target->subject);
            })
            ->orderByDesc('id')
            ->first();

        if ($activity === null) {
            throw new RuntimeException('Expected an activity row for the API-key write.');
        }

        $properties = json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
        $recorded = $properties['api_key_id'] ?? null;

        if (! is_string($recorded) || ! Str::isUuid($recorded) || $recorded !== $created['apiKey']->uuid) {
            throw new RuntimeException('activity properties.api_key_id must be the key public UUID.');
        }
    },

    'assertInitialAdminSeeder' => static function (): void {
        syncPermissions();

        $email = 'conformance-admin-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'C0nform4nce-Strong!Pass';

        config([
            'super_admin.email' => $email,
            'super_admin.password' => $password,
            'super_admin.first_name' => 'Conformance',
            'super_admin.last_name' => 'Admin',
        ]);

        Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            throw new RuntimeException('The seeder did not create the admin with the explicit strong password.');
        }

        if (! $user->hasRole(RoleEnum::SuperAdmin->value)) {
            throw new RuntimeException('The seeded admin does not hold the super-admin role.');
        }

        config(['super_admin.password' => 'Another-Strong!Pass']);
        Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);

        $afterReseed = User::query()->findOrFail($user->getKey());

        if (! Hash::check($password, (string) $afterReseed->password)) {
            throw new RuntimeException('Re-seeding rewrote an existing admin.');
        }

        if (app(InitialSuperAdminPasswordService::class)->rejectionReasons('password', 'production') === []) {
            throw new RuntimeException('The known default password is accepted outside local/testing.');
        }
    },

    'assertErrorContract' => static function (): void {
        config(['app.debug' => false]);

        $request = Request::create('/api/v1/users', 'GET');
        $request->headers->set('Accept', 'application/json');
        $response = app(HttpKernel::class)->handle($request);
        $payload = json_decode((string) $response->getContent(), true);

        if (! is_array($payload) || ! isset($payload['error']) || ! is_string($payload['error'])) {
            throw new RuntimeException('Machine errors must expose an error field.');
        }

        if (array_key_exists('code', $payload)) {
            throw new RuntimeException('Machine errors must not expose a code field.');
        }

        foreach (['trace', 'exception', 'file', 'line'] as $debugKey) {
            if (array_key_exists($debugKey, $payload)) {
                throw new RuntimeException("APP_DEBUG=false must not expose {$debugKey}.");
            }
        }

        $known = array_map(
            static fn (ApiErrorCodeEnum $case): string => $case->value,
            ApiErrorCodeEnum::cases(),
        );
        $known[] = 'invalid_token';
        $known[] = 'insufficient_scope';

        if (! in_array($payload['error'], $known, true)) {
            throw new RuntimeException('error must be a documented machine code; got '.$payload['error']);
        }
    },

    'assertContentLanguage' => static function (): void {
        $okRequest = Request::create('/api/v1/openapi.json', 'GET');
        $okRequest->headers->set('Accept', 'application/json');
        $okRequest->headers->set('Accept-Language', 'it');
        $ok = app(HttpKernel::class)->handle($okRequest);

        if ($ok->headers->get('Content-Language') !== 'it') {
            throw new RuntimeException('Success responses must set Content-Language from Accept-Language.');
        }

        $errorRequest = Request::create('/api/v1/users', 'GET');
        $errorRequest->headers->set('Accept', 'application/json');
        $errorRequest->headers->set('Accept-Language', 'de');
        $error = app(HttpKernel::class)->handle($errorRequest);

        if ($error->getStatusCode() < 400) {
            throw new RuntimeException('Expected an unauthenticated error for Content-Language check.');
        }

        if ($error->headers->get('Content-Language') !== 'de') {
            throw new RuntimeException('Error responses must set Content-Language from Accept-Language.');
        }
    },

    'assertPaginationShape' => static function (): void {
        $created = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

        $request = Request::create('/api/v1/users', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$created['plain']);
        $request->headers->set('Accept', 'application/json');
        $response = app(HttpKernel::class)->handle($request);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('The paginated JSON endpoint did not answer 200: '.$response->getStatusCode());
        }

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (array_keys($payload) !== ['data', 'links', 'meta']) {
            throw new RuntimeException('Pagination shape must be exactly data, links, meta; got '.implode(', ', array_keys($payload)));
        }
    },

    'assertSsrfGuard' => static function (): void {
        $guard = app(WebhookUrlGuardService::class);

        $metadata = $guard->authorizeOutbound('http://169.254.169.254/latest/meta-data/');

        if (($metadata['ok'] ?? true) === true) {
            throw new RuntimeException('SSRF guard must refuse the EC2 metadata address.');
        }

        $decimal = $guard->authorizeOutbound('http://2852039166/');

        if (($decimal['ok'] ?? true) === true) {
            throw new RuntimeException('SSRF guard must refuse non-dotted-decimal IPv4 spellings of metadata.');
        }

        $safe = $guard->authorizeOutbound('https://example.com/hooks');

        if (($safe['ok'] ?? false) !== true) {
            throw new RuntimeException('SSRF guard must allow a public HTTPS URL: '.($safe['reason'] ?? 'unknown'));
        }

        if (! isset($safe['curl_connect_to']) || $safe['curl_connect_to'] === []) {
            throw new RuntimeException('SSRF guard must pin with CURLOPT_CONNECT_TO entries.');
        }
    },

    'assertSuperAdmin' => static function (): void {
        syncPermissions();

        $superAdmin = User::factory()->instanceAdmin()->create();
        $resolver = app(EffectivePermissionResolverService::class);

        if (! $resolver->isSuperAdmin($superAdmin)) {
            throw new RuntimeException('Instance super-admin must resolve with zero organisations.');
        }

        if (OrganizationMembership::query()->where('user_id', $superAdmin->getKey())->exists()) {
            throw new RuntimeException('Conformance super-admin fixture must have no organisations.');
        }
    },

    'assertApiResourcesHideBigints' => static function (): void {
        $created = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
        $target = User::factory()->create();

        $request = Request::create('/api/v1/users/'.$target->subject, 'GET');
        $request->headers->set('Authorization', 'Bearer '.$created['plain']);
        $request->headers->set('Accept', 'application/json');
        $response = app(HttpKernel::class)->handle($request);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('User show failed for C31: '.$response->getContent());
        }

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $payload['data']['id'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id) || $id !== $target->subject) {
            throw new RuntimeException('API user id must be the public subject UUID.');
        }

        if (array_key_exists('uuid', $payload['data'] ?? [])) {
            throw new RuntimeException('API user payload must not expose a separate uuid field alongside id.');
        }

        $encoded = (string) $response->getContent();

        if (str_contains($encoded, '"id":'.$target->getKey()) || str_contains($encoded, '"id": '.$target->getKey())) {
            throw new RuntimeException('API user payload must not expose the internal bigint id.');
        }
    },

    'assertEncryptedCasts' => static function (): void {
        $secret = 'JBSWY3DPEHPK3PXP';
        $recoveryCodes = ['conformance-recovery-code'];

        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $storedUser = DB::table('users')->where('id', $user->getKey())->first();

        if ($storedUser === null || ! str_starts_with((string) $storedUser->two_factor_secret, 'eyJpdiI6')) {
            throw new RuntimeException('The 2FA secret is not stored encrypted.');
        }

        if (Fortify::currentEncrypter()->decrypt((string) $user->fresh()->two_factor_secret) !== $secret) {
            throw new RuntimeException('The encrypted 2FA secret does not round-trip.');
        }

        $webhook = existingWebhook(['secret' => 'whsec_conformance_secret']);
        $webhookRow = DB::table('webhooks')->where('id', $webhook->getKey())->first();

        if ($webhookRow === null || ! str_starts_with((string) $webhookRow->secret, 'eyJpdiI6')) {
            throw new RuntimeException('Webhook secrets must use the encrypted cast.');
        }

        app(SigningKeyService::class)->activeKey();
        $signingRow = DB::table('signing_keys')->orderByDesc('id')->first();

        if ($signingRow === null || ! str_starts_with((string) $signingRow->private_key, 'eyJpdiI6')) {
            throw new RuntimeException('Signing key private material must use the encrypted cast.');
        }

        if (! SigningKey::query()->exists()) {
            throw new RuntimeException('Expected a signing key row after activeKey().');
        }

        app(LdapSettingsService::class)->save(new LdapSettingsData(
            isEnabled: false,
            hosts: ['ldap.example.test'],
            port: 636,
            encryption: LdapEncryptionEnum::Tls,
            bindDn: 'cn=binder,dc=example,dc=test',
            bindPassword: 'ldap-bind-secret',
            baseDn: 'dc=example,dc=test',
            userFilter: '(objectClass=person)',
            attributeMail: 'mail',
            attributeGivenName: 'givenName',
            attributeFamilyName: 'sn',
            attributeDisplayName: 'displayName',
            attributeUsername: 'uid',
            attributeGuid: 'objectGUID',
            syncIntervalMinutes: 60,
        ));

        $rawBind = app(LdapSettingsService::class)->raw()['bind_password'] ?? null;

        if (! is_string($rawBind) || $rawBind === '' || Crypt::decryptString($rawBind) !== 'ldap-bind-secret') {
            throw new RuntimeException('LDAP bind password must be encrypted at rest.');
        }

        $previousKey = random_bytes(32);
        $previousEncrypter = new FrameworkEncrypter($previousKey, strtolower((string) config('app.cipher')));
        $oldPayload = $previousEncrypter->encrypt('rotated-secret');
        $currentEncrypter = app(Encrypter::class);
        $currentEncrypter->previousKeys([$previousKey]);

        try {
            if (Crypt::decrypt($oldPayload) !== 'rotated-secret') {
                throw new RuntimeException('APP_PREVIOUS_KEYS did not decrypt a value written by the old key.');
            }
        } finally {
            $currentEncrypter->previousKeys([]);
        }
    },
];
