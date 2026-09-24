<?php

namespace App\Modules\Publishing\Domain\Publication;

enum ProviderMediaReferenceState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [
                self::Processing,
                self::Ready,
                self::Failed,
                self::Expired,
            ], true),
            self::Processing => in_array($next, [
                self::Ready,
                self::Failed,
                self::Expired,
            ], true),
            self::Ready => $next === self::Expired,
            self::Failed, self::Expired => false,
        };
    }
}
