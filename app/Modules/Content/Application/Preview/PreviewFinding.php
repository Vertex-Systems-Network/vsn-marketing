<?php

namespace App\Modules\Content\Application\Preview;

use InvalidArgumentException;

final readonly class PreviewFinding
{
    private const array SEVERITIES = ['error', 'info', 'warning'];

    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public ?string $path = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/i', $this->code) !== 1) {
            throw new InvalidArgumentException('Preview finding code must be a bounded stable identifier.');
        }

        if (in_array($this->severity, self::SEVERITIES, true) === false) {
            throw new InvalidArgumentException('Preview finding severity must be error, warning, or info.');
        }

        if (trim($this->message) === '' || strlen($this->message) > 512) {
            throw new InvalidArgumentException('Preview finding message must be non-empty and at most 512 bytes.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $this->message) === 1) {
            throw new InvalidArgumentException('Preview finding message contains forbidden control characters.');
        }

        if ($this->path !== null && preg_match('/^[a-z0-9._:\/#-]{1,256}$/i', $this->path) !== 1) {
            throw new InvalidArgumentException('Preview finding path must be a bounded stable path identifier.');
        }
    }

    /** @return array{code: string, severity: string, message: string, path: string|null} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'path' => $this->path,
        ];
    }

    public function sortKey(): string
    {
        return implode("\x1F", [
            $this->severity,
            $this->code,
            $this->path ?? '',
            $this->message,
        ]);
    }
}
