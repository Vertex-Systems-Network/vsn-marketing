<?php

namespace App\Modules\Providers\Domain\Feedback;

use DateTimeImmutable;

final class EvaluateProviderFeedback
{
    public function evaluate(ProviderFeedbackEvidence $evidence, DateTimeImmutable $now): ProviderFeedbackDisposition
    {
        if (! $evidence->trustedSource) {
            return ProviderFeedbackDisposition::Reject;
        }

        if ($evidence->freshUntil !== null && $now > $evidence->freshUntil) {
            return ProviderFeedbackDisposition::Reject;
        }

        return match ($evidence->classification) {
            ProviderFeedbackClassification::PermanentBounce,
            ProviderFeedbackClassification::Complaint,
            ProviderFeedbackClassification::AcceptedUnsubscribe => ProviderFeedbackDisposition::Suppress,
            ProviderFeedbackClassification::TransientBounce => ProviderFeedbackDisposition::Review,
        };
    }
}
