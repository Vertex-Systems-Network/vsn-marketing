<?php

namespace App\Modules\Providers\Infrastructure\Connectors\Brevo;

use App\Modules\Providers\Domain\Connectors\Contracts\WebhookVerifier;
use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationResult;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationStatus;
use JsonException;

final readonly class BrevoWebhookVerifier implements WebhookVerifier
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        private string $strategy,
        private array $configuration,
    ) {}

    public function verify(WebhookRequest $request): WebhookVerificationResult
    {
        $authenticated = match ($this->strategy) {
            'source_ip' => $this->verifySourceAddress($request),
            'basic' => $this->verifyBasic($request),
            'bearer' => $this->verifyBearer($request),
            'custom_headers' => $this->verifyCustomHeaders($request),
            default => null,
        };

        if ($authenticated === null) {
            return new WebhookVerificationResult(
                status: WebhookVerificationStatus::Unsupported,
                strategy: $this->strategy,
                reason: 'Configured Brevo webhook verification strategy is unsupported or incomplete.',
            );
        }

        if (! $authenticated) {
            return new WebhookVerificationResult(
                status: WebhookVerificationStatus::Rejected,
                strategy: $this->strategy,
                reason: 'Brevo webhook authentication evidence did not match the configured verifier.',
            );
        }

        try {
            $payload = json_decode($request->rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new WebhookVerificationResult(
                status: WebhookVerificationStatus::Rejected,
                strategy: $this->strategy,
                reason: 'Brevo webhook body is not valid JSON.',
            );
        }

        if (! is_array($payload)) {
            return new WebhookVerificationResult(
                status: WebhookVerificationStatus::Rejected,
                strategy: $this->strategy,
                reason: 'Brevo webhook body must decode to an object.',
            );
        }

        $sourceEventId = $this->scalarString($payload['id'] ?? null)
            ?? $this->scalarString($payload['message-id'] ?? null);

        return new WebhookVerificationResult(
            status: WebhookVerificationStatus::Verified,
            strategy: $this->strategy,
            deduplicationKey: hash('sha256', $request->rawBody),
            sourceEventId: $sourceEventId,
            evidence: [
                'event' => $this->scalarString($payload['event'] ?? null),
                'source_address' => $request->sourceAddress,
            ],
        );
    }

    private function verifySourceAddress(WebhookRequest $request): ?bool
    {
        $allowed = $this->configuration['allowed_source_addresses'] ?? null;
        if (! is_array($allowed) || $request->sourceAddress === null) {
            return null;
        }

        foreach ($allowed as $candidate) {
            if (is_string($candidate) && $this->addressMatches($request->sourceAddress, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function verifyBasic(WebhookRequest $request): ?bool
    {
        $username = $this->configuration['username'] ?? null;
        $password = $this->configuration['password'] ?? null;
        if (! is_string($username) || ! is_string($password)) {
            return null;
        }

        $actual = $this->header($request, 'authorization');
        if ($actual === null) {
            return false;
        }

        return hash_equals('Basic '.base64_encode($username.':'.$password), $actual);
    }

    private function verifyBearer(WebhookRequest $request): ?bool
    {
        $token = $this->configuration['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $actual = $this->header($request, 'authorization');
        if ($actual === null) {
            return false;
        }

        return hash_equals('Bearer '.$token, $actual);
    }

    private function verifyCustomHeaders(WebhookRequest $request): ?bool
    {
        $expected = $this->configuration['headers'] ?? null;
        if (! is_array($expected) || $expected === []) {
            return null;
        }

        foreach ($expected as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                return null;
            }

            $actual = $this->header($request, $name);
            if ($actual === null || ! hash_equals($value, $actual)) {
                return false;
            }
        }

        return true;
    }

    private function header(WebhookRequest $request, string $name): ?string
    {
        foreach ($request->headers as $header => $value) {
            if (strtolower($header) !== strtolower($name)) {
                continue;
            }

            $raw = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($raw) ? $raw : null;
        }

        return null;
    }

    private function scalarString(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : null;
    }

    private function addressMatches(string $address, string $candidate): bool
    {
        if (! str_contains($candidate, '/')) {
            return hash_equals($candidate, $address);
        }

        [$network, $prefixRaw] = array_pad(explode('/', $candidate, 2), 2, null);
        if (! is_string($network) || ! is_string($prefixRaw) || ! ctype_digit($prefixRaw)) {
            return false;
        }

        $addressBinary = inet_pton($address);
        $networkBinary = inet_pton($network);
        if ($addressBinary === false || $networkBinary === false || strlen($addressBinary) !== strlen($networkBinary)) {
            return false;
        }

        $prefix = (int) $prefixRaw;
        $maxBits = strlen($addressBinary) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($addressBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
