<?php

use Monolog\Formatter\JsonFormatter;

test('correlation ids are propagated and unsafe values are replaced', function () {
    $this->getJson('/api/health/live', ['X-Correlation-ID' => 'request-123'])
        ->assertOk()
        ->assertHeader('X-Correlation-ID', 'request-123')
        ->assertJson([
            'status' => 'ok',
            'correlation_id' => 'request-123',
        ]);

    $response = $this->getJson('/api/health/live', ['X-Correlation-ID' => "unsafe\nvalue"]);

    $response->assertOk();
    expect($response->headers->get('X-Correlation-ID'))
        ->not->toBeNull()
        ->not->toBe("unsafe\nvalue");
});

test('readiness verifies database and cache dependencies for authorized operations', function () {
    $token = str_repeat('b', 32);
    config()->set('operations.token', $token);

    $this->withHeader('X-Operations-Token', $token)
        ->getJson('/api/health/ready')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store')
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonPath('checks.cache', 'ok');
});

test('baseline metrics expose aggregate request counters without request labels to authorized operations', function () {
    $token = str_repeat('c', 32);
    config()->set('operations.token', $token);

    $headers = ['X-Operations-Token' => $token];
    $this->getJson('/api/runtime', $headers)->assertOk();

    $response = $this->get('/api/metrics', $headers)->assertOk();
    $body = $response->getContent();

    expect($body)
        ->toContain('vsn_http_requests_total')
        ->toContain('vsn_http_responses_total{class="2xx"}')
        ->not->toContain('/api/runtime');
});

test('application log channels use structured json formatting', function () {
    expect(config('logging.channels.single.formatter'))->toBe(JsonFormatter::class)
        ->and(config('logging.channels.stderr.formatter'))->toBe(JsonFormatter::class);
});
