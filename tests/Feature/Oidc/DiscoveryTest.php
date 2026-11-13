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
            'jwks_uri' => 'https://id.example.test/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'end_session_endpoint' => 'https://id.example.test/oauth/end-session',
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
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
