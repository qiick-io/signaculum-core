<?php

declare(strict_types=1);

test('liveness returns 200 when the app responds', function () {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('the framework up probe still returns 200', function () {
    $this->get('/up')->assertOk();
});

test('readiness returns 200 when the database answers', function () {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok');
});
