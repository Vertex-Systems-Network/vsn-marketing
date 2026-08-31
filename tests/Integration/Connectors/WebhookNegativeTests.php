<?php

declare(strict_types=1);

namespace Tests\Integration\Connectors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookNegativeTests extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_invalid_signature()
    {
        // TODO: implement integration test that posts a webhook with invalid signature and asserts 400/401
        $this->markTestIncomplete('Integration test scaffold - needs provider webhook route and verifier implementation.');
    }

    public function test_replay_detection()
    {
        $this->markTestIncomplete('Replay detection test scaffold.');
    }
}
