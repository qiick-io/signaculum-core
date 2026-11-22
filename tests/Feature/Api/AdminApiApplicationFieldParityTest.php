<?php

declare(strict_types=1);

use App\Data\ApplicationData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Resources\Api\V1\ApplicationResource;
use App\Models\Client;

/**
 * #186: applications have two doors, and until now each one restated its own
 * validation and built {@see ApplicationData} field by field. A field added to the
 * admin form reached the form request, the web resource and the UI, and never the
 * Admin REST API — with no error anywhere. `is_device_authorization_enabled` (#110)
 * lived like that; `frontchannel_logout_uri` (#141) did before it.
 *
 * So this walks `ApplicationData` itself instead of a hand-written list of fields:
 * every promoted property must be accepted by the Admin API's create validation and
 * returned by {@see ApplicationResource}. That is the half of the guard that covers
 * the fields nobody has written yet.
 */

/**
 * One probe per promoted property of {@see ApplicationData}: the API field it is
 * written and read through, a non-default value to send, and the value the Admin
 * API must read back.
 *
 * The values are deliberately non-default — `true` for every flag, a URI for every
 * URI — so a field the API drops shows up as its default rather than passing by
 * accident.
 *
 * @return array<string, array{field: string, send: mixed, expect: mixed}>
 */
function applicationDataFieldProbes(): array
{
    return [
        'name' => [
            'field' => 'name',
            'send' => 'Enumerated app',
            'expect' => 'Enumerated app',
        ],
        'redirectUris' => [
            'field' => 'redirect_uris',
            'send' => ['https://enumerated.test/auth/oidc/callback'],
            'expect' => ['https://enumerated.test/auth/oidc/callback'],
        ],
        'isConfidential' => [
            'field' => 'is_confidential',
            'send' => true,
            'expect' => true,
        ],
        'isFirstParty' => [
            'field' => 'is_first_party',
            'send' => true,
            'expect' => true,
        ],
        'isClientCredentialsEnabled' => [
            'field' => 'is_client_credentials_enabled',
            'send' => true,
            'expect' => true,
        ],
        'isDeviceAuthorizationEnabled' => [
            'field' => 'is_device_authorization_enabled',
            'send' => true,
            'expect' => true,
        ],
        'isPushedAuthorizationRequired' => [
            'field' => 'is_pushed_authorization_required',
            'send' => true,
            'expect' => true,
        ],
        'frontchannelLogoutUri' => [
            'field' => 'frontchannel_logout_uri',
            'send' => 'https://enumerated.test/logout',
            'expect' => 'https://enumerated.test/logout',
        ],
        'isFrontchannelLogoutSessionRequired' => [
            'field' => 'is_frontchannel_logout_session_required',
            'send' => true,
            'expect' => true,
        ],
    ];
}

it('probes every property ApplicationData carries', function (): void {
    $probes = applicationDataFieldProbes();
    $constructor = (new ReflectionClass(ApplicationData::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    $properties = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor->getParameters(),
    );

    foreach ($properties as $property) {
        expect(array_key_exists($property, $probes))->toBeTrue(sprintf(
            'ApplicationData::$%s has no probe in %s. A field on this payload has to be '
            .'accepted by the Admin API create validation and returned by '
            .'App\Http\Resources\Api\V1\ApplicationResource as well as by the admin form: '
            .'carry it through both doors, then add its probe here (#186).',
            $property,
            basename(__FILE__),
        ));
    }

    // A probe with no property behind it is a field that was removed from one door only.
    expect(array_keys($probes))->toEqualCanonicalizing($properties);
});

it('accepts every ApplicationData field on create and reads every one back', function (): void {
    ['plain' => $plain] = adminApiKey([
        ApiKeyScopeEnum::ApplicationsWrite->value,
        ApiKeyScopeEnum::ApplicationsRead->value,
    ]);

    $probes = applicationDataFieldProbes();

    $payload = [];

    foreach ($probes as $probe) {
        $payload[$probe['field']] = $probe['send'];
    }

    $created = $this->withToken($plain)
        ->postJson('/api/v1/applications', $payload)
        ->assertCreated();

    /** @var array<string, mixed> $data */
    $data = $created->json('data');

    foreach ($probes as $property => $probe) {
        expect(array_key_exists($probe['field'], $data))->toBeTrue(sprintf(
            'ApplicationData::$%s is not in the Admin API create response: add `%s` to '
            .'App\Http\Resources\Api\V1\ApplicationResource (#186).',
            $property,
            $probe['field'],
        ));

        expect($data[$probe['field']])->toEqual($probe['expect'], sprintf(
            'ApplicationData::$%s was dropped between the Admin API create request and its '
            .'response: add `%s` to the create validation in '
            .'App\Http\Controllers\Api\V1\ApplicationController (#186).',
            $property,
            $probe['field'],
        ));
    }

    // Read it back from the row, not from the create response: a field accepted into
    // the payload and never persisted would otherwise pass.
    $application = Client::query()->where('name', $probes['name']['send'])->firstOrFail();

    $shown = $this->withToken($plain)
        ->getJson('/api/v1/applications/'.$application->getKey())
        ->assertOk();

    foreach ($probes as $property => $probe) {
        expect($shown->json('data.'.$probe['field']))->toEqual($probe['expect'], sprintf(
            'ApplicationData::$%s did not survive the round trip through the database: '
            .'the Admin API accepted `%s` but reads back something else (#186).',
            $property,
            $probe['field'],
        ));
    }
});
