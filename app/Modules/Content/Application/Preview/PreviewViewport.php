<?php

namespace App\Modules\Content\Application\Preview;

use InvalidArgumentException;

final readonly class PreviewViewport
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public int $deviceScaleFactor = 1,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', $this->name) !== 1) {
            throw new InvalidArgumentException('Preview viewport name must be a bounded stable identifier.');
        }

        if ($this->width < 240 || $this->width > 3840) {
            throw new InvalidArgumentException('Preview viewport width must be between 240 and 3840 pixels.');
        }

        if ($this->height < 240 || $this->height > 4320) {
            throw new InvalidArgumentException('Preview viewport height must be between 240 and 4320 pixels.');
        }

        if ($this->deviceScaleFactor < 1 || $this->deviceScaleFactor > 4) {
            throw new InvalidArgumentException('Preview viewport device scale factor must be between 1 and 4.');
        }
    }

    /** @return array{name: string, width: int, height: int, device_scale_factor: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'device_scale_factor' => $this->deviceScaleFactor,
        ];
    }
}
