<?php

namespace App\Modules\Identity\Domain\Governance;

/** Appearance-only fields; ownership, origin, consent and billing stay canonical. */
final class WhiteLabelAppearanceAdmission
{
    private const ALLOWED = ['display_name', 'accent_color', 'logo_asset_id'];

    /** @param array<string, mixed> $settings */
    public function allows(array $settings): bool
    {
        if ($settings === [] || array_diff(array_keys($settings), self::ALLOWED) !== []) {
            return false;
        }

        foreach ($settings as $field => $value) {
            if ($field === 'logo_asset_id' && $value === null) {
                continue;
            }
            if (! is_string($value)) {
                return false;
            }

            if ($field === 'display_name' && ($value === '' || mb_strlen($value) > 80 || preg_match('/[<>\x00-\x1F]/u', $value))) {
                return false;
            }

            if ($field === 'accent_color' && (! preg_match('/^#[0-9a-fA-F]{6}$/D', $value)
                || ! $this->contrastsWithWhite($value))) {
                return false;
            }

            if ($field === 'logo_asset_id' && ! preg_match('/^[0-9a-fA-F-]{36}$/D', $value)) {
                return false;
            }
        }

        return true;
    }

    private function contrastsWithWhite(string $hex): bool
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $channel = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05) >= 4.5;
    }
}
