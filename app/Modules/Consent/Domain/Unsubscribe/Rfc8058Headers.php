<?php

namespace App\Modules\Consent\Domain\Unsubscribe;

use InvalidArgumentException;

final readonly class Rfc8058Headers
{
    public const LIST_UNSUBSCRIBE = 'List-Unsubscribe';
    public const LIST_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';
    public const ONE_CLICK_POST_VALUE = 'List-Unsubscribe=One-Click';

    public string $listUnsubscribe;
    public string $listUnsubscribePost;

    public function __construct(string $httpsEndpoint, OpaqueUnsubscribeToken $token)
    {
        $parts = parse_url($httpsEndpoint);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || trim((string) ($parts['host'] ?? '')) === '') {
            throw new InvalidArgumentException('RFC 8058 one-click endpoint must use HTTPS.');
        }

        $separator = str_contains($httpsEndpoint, '?') ? '&' : '?';
        $url = $httpsEndpoint.$separator.'token='.rawurlencode($token->value);

        $this->listUnsubscribe = '<'.$url.'>';
        $this->listUnsubscribePost = self::ONE_CLICK_POST_VALUE;
    }

    /** @return array<string, string> */
    public function asArray(): array
    {
        return [
            self::LIST_UNSUBSCRIBE => $this->listUnsubscribe,
            self::LIST_UNSUBSCRIBE_POST => $this->listUnsubscribePost,
        ];
    }

    /** @param list<string> $coveredHeaders */
    public static function coveredByDkim(array $coveredHeaders): bool
    {
        $normalized = array_map(static fn (string $header): string => strtolower(trim($header)), $coveredHeaders);

        return in_array(strtolower(self::LIST_UNSUBSCRIBE), $normalized, true)
            && in_array(strtolower(self::LIST_UNSUBSCRIBE_POST), $normalized, true);
    }
}
