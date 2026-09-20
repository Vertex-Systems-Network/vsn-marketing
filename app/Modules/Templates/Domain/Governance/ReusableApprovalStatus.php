<?php

namespace App\Modules\Templates\Domain\Governance;

enum ReusableApprovalStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Approved = 'approved';
    case Retired = 'retired';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Ready,
            self::Ready => in_array($next, [self::Draft, self::Approved], true),
            self::Approved => $next === self::Retired,
            self::Retired => false,
        };
    }

    public function protectsExactVersion(): bool
    {
        return $this === self::Approved || $this === self::Retired;
    }
}
