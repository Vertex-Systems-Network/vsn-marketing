<?php

test('the core runtime endpoint is hidden without operations authorization', function () {
    config()->set('operations.token', str_repeat('a', 32));

    $this->getJson('/api/runtime')->assertNotFound();
});

test('the core runtime endpoint exposes a bounded foundation snapshot to authorized operations', function () {
    $token = str_repeat('a', 32);
    config()->set('operations.token', $token);

    $response = $this->withHeader('X-Operations-Token', $token)
        ->getJson('/api/runtime')
        ->assertOk()
        ->assertJsonPath('data.name', 'VSN Marketing')
        ->assertJsonStructure(['data' => ['name', 'environment', 'php', 'time']]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
