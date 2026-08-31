<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use App\Connectors\Webhook\GenericWebhookVerifier;

class WebhookVerifierTest extends TestCase
{
    public function test_deduplication_id_from_json()
    {
        $v = new GenericWebhookVerifier();
        $body = json_encode(['id' => 'abc-123']);
        $id = $v->deduplicationId($body, []);
        $this->assertEquals('abc-123', $id);
    }

    public function test_verify_returns_false_without_secret_or_signature()
    {
        $v = new GenericWebhookVerifier();
        $ok = $v->verify('payload', []);
        $this->assertFalse($ok);
    }
}
