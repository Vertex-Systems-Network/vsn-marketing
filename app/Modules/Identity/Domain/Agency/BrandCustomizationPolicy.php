<?php

namespace App\Modules\Identity\Domain\Agency;

/**
 * White-label input is cosmetic only. It can never carry security origin,
 * consent, ownership or billing statements; those are system-rendered.
 */
final class BrandCustomizationPolicy
{
    private const RESERVED = ['/\b(verified|official|secure[d]?|login|sign[ -]?in|password)\b/i',
        '/\b(consent|unsubscribe|opt[ -]?(in|out)|privacy)\b/i', '/\b(billing|invoice|payment|owned by|operated by)\b/i'];

    /** @param array<string, mixed> $input @return array{display_name: string, primary_color: string, logo_url: ?string}|null */
    public function sanitize(array $input): ?array
    {
        if (array_diff(array_keys($input), ['display_name', 'primary_color', 'logo_url']) !== []) {
            return null;
        }
        $name = $input['display_name'] ?? null;
        $color = $input['primary_color'] ?? null;
        $logo = $input['logo_url'] ?? null;
        if (! is_string($name) || ($name = trim($name)) === '' || mb_strlen($name) > 80
            || preg_match('/[\x00-\x1F\x7F<>]|https?:|\.[a-z]{2,}\b/i', $name) === 1) {
            return null;
        }
        foreach (self::RESERVED as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return null;
            }
        }
        if (! is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
            return null;
        }
        if ($logo !== null) {
            $parts = is_string($logo) && strlen($logo) <= 2048 ? parse_url($logo) : false;
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }
        }

        return ['display_name' => $name, 'primary_color' => strtolower($color), 'logo_url' => $logo];
    }
}
