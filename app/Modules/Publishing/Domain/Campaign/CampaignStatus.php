<?php

namespace App\Modules\Publishing\Domain\Campaign;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case NeedsApproval = 'needs_approval';
    case Approved = 'approved';
    case Ready = 'ready';
    case ScheduledIntent = 'scheduled_intent';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Review, self::Cancelled], true),
            self::Review => in_array($next, [self::Draft, self::NeedsApproval, self::Ready, self::Cancelled], true),
            self::NeedsApproval => in_array($next, [self::Draft, self::Approved, self::Cancelled], true),
            self::Approved => in_array($next, [self::Ready, self::ScheduledIntent, self::Cancelled], true),
            self::Ready => in_array($next, [self::ScheduledIntent, self::Cancelled, self::Completed], true),
            self::ScheduledIntent => in_array($next, [self::Ready, self::Cancelled, self::Completed], true),
            self::Cancelled, self::Completed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Completed;
    }
}
