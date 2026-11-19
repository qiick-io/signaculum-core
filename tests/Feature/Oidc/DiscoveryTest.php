<?php

declare(strict_types=1);

test('discovery publishes the qiick protocol profile', function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->get('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJson([
            'issuer' => 'https://id.example.test',
            'authorization_endpoint' => 'https://id.example.test/oauth/authorize',
            'token_endpoint' => 'https://id.example.test/oauth/token',
            'userinfo_endpoint' => 'https://id.example.test/oauth/userinfo',
            'introspection_endpoint' => 'https://id.example.test/oauth/introspect',
            'revocation_endpoint' => 'https://id.example.test/oauth/revoke',
            'pushed_authorization_request_endpoint' => 'https://id.example.test/oauth/par',
            'require_pushed_authorization_requests' => false,
            'jwks_uri' => 'https://id.example.test/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'device_authorization_endpoint' => 'https://id.example.test/oauth/device/code',
            'grant_types_supported' => [
                'authorization_code',
                'refresh_token',
                'client_credentials',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'profile', 'email', 'qiick.orgs'],
            'end_session_endpoint' => 'https://id.example.test/oauth/end-session',
            'introspection_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
            ],
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
            'frontchannel_logout_supported' => true,
            'frontchannel_logout_session_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'revocation_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
                'none',
            ],
        ]);

    expect($this->get('/.well-known/openid-configuration')->json('claims_supported'))
        ->toContain('sid');
});

test('discovery is public and cacheable', function () {
    $this->get('/.well-known/openid-configuration')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeaderContains('Cache-Control', 'public');
});

test('the issuer has no trailing slash', function () {
    config(['oidc.issuer' => rtrim('https://id.example.test/', '/')]);

    expect($this->getJson('/.well-known/openid-configuration')->json('issuer'))
        ->toBe('https://id.example.test');
});
