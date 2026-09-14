<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps minimal liveness public while hiding detailed operations endpoints', function () {
    config()->set('operations.token', str_repeat('a', 32));

    $this->getJson('/api/health/live')
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    foreach (['/api/runtime', '/api/health/ready', '/api/metrics'] as $path) {
        $this->get($path)->assertNotFound();
        $this->withHeader('X-Operations-Token', 'too-short')->get($path)->assertNotFound();
        $this->withHeader('X-Operations-Token', str_repeat('z', 32))->get($path)->assertNotFound();
    }
});

it('fails closed when the operations token is not configured strongly enough', function () {
    config()->set('operations.token', null);
    $this->get('/api/runtime')->assertNotFound();

    config()->set('operations.token', str_repeat('a', 31));
    $this->withHeader('X-Operations-Token', str_repeat('a', 31))
        ->get('/api/runtime')
        ->assertNotFound();
});

it('emits conservative baseline browser security headers', function () {
    $this->getJson('/api/health/live')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-XSS-Protection', '0')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader(
            'Content-Security-Policy',
            "frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
        );
});

it('rate limits repeated login attempts against one account even when source IP rotates', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$attempt}"])
            ->post('/auth/login', [
                'email' => 'target@example.test',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('email');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])
        ->post('/auth/login', [
            'email' => 'target@example.test',
            'password' => 'wrong-password',
        ])
        ->assertStatus(429);
});

it('rate limits one source IP even when attempted account identifiers rotate', function () {
    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/auth/login', [
                'email' => "attempt-{$attempt}@example.test",
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('email');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->post('/auth/login', [
            'email' => 'attempt-21@example.test',
            'password' => 'wrong-password',
        ])
        ->assertStatus(429);
});
