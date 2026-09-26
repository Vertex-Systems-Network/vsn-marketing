<?php

namespace App\Modules\Segmentation\Domain;

use InvalidArgumentException;

final readonly class SegmentProposalResponse
{
    /**
     * @param array<string, mixed>|null $definition
     * @param list<string> $clarificationQuestions
     */
    private function __construct(
        public string $status,
        public ?array $definition = null,
        public array $clarificationQuestions = [],
        public ?string $routeVersion = null,
        public ?string $failureCode = null,
    ) {
        if (in_array($status, ['proposed', 'clarification_required', 'unavailable', 'refused', 'failed'], true) !== true) {
            throw new InvalidArgumentException('Unsupported segment proposal status.');
        }

        if ($status === 'proposed' && $definition === null) {
            throw new InvalidArgumentException('A proposed response requires a structured definition.');
        }

        if ($status !== 'proposed' && $definition !== null) {
            throw new InvalidArgumentException('Only a proposed response may contain a definition.');
        }

        foreach ($clarificationQuestions as $question) {
            if (! is_string($question)) {
                throw new InvalidArgumentException('Clarification questions must be strings.');
            }
        }
    }

    /** @param array<string, mixed> $definition */
    public static function proposed(array $definition, ?string $routeVersion = null): self
    {
        return new self('proposed', $definition, [], $routeVersion);
    }

    /** @param list<string> $questions */
    public static function clarificationRequired(array $questions, ?string $routeVersion = null): self
    {
        return new self('clarification_required', null, array_slice($questions, 0, 3), $routeVersion);
    }

    public static function unavailable(): self
    {
        return new self('unavailable');
    }

    public static function refused(): self
    {
        return new self('refused');
    }

    public static function failed(string $reason = 'provider_error'): self
    {
        $safeReason = preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reason) === 1 ? $reason : 'provider_error';

        return new self('failed', null, [], null, $safeReason);
    }
}
