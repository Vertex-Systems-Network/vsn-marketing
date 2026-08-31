<?php

namespace App\Modules\Providers\Domain\Connectors;

use DateTimeImmutable;

final readonly class WebhookRequest
{
    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string|list<string>> $query
     */
    public function __construct(
        public string $rawBody,
        public array $headers,
        public array $query,
        public DateTimeImmutable $receivedAt,
        public ?string $sourceAddress = null,
    ) {
    }
}
