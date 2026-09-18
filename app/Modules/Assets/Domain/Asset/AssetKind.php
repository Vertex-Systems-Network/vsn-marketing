<?php

namespace App\Modules\Assets\Domain\Asset;

enum AssetKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Binary = 'binary';

    public function acceptsMediaType(string $mediaType): bool
    {
        $mediaType = strtolower(trim($mediaType));

        return match ($this) {
            self::Image => str_starts_with($mediaType, 'image/'),
            self::Video => str_starts_with($mediaType, 'video/'),
            self::Audio => str_starts_with($mediaType, 'audio/'),
            self::Document => str_starts_with($mediaType, 'application/') || str_starts_with($mediaType, 'text/'),
            self::Binary => $mediaType === 'application/octet-stream',
        };
    }
}
