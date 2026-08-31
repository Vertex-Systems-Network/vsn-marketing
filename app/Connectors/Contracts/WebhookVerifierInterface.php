<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

interface WebhookVerifierInterface
{
    /**
     * Verify authenticity of a raw request body and headers. Implementations MUST be able to access raw body
     * and return boolean. Throwing exceptions is allowed to convey fatal verification errors.
     *
     * @param string $rawBody
     * @param array $headers
     * @return bool
     */
    public function verify(string $rawBody, array $headers): bool;

    /**
     * Extract a durable deduplication/replay id from headers/body if provided by provider; return null if none.
     */
    public function deduplicationId(string $rawBody, array $headers): ?string;
}
