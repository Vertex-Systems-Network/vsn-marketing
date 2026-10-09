# TASK-0091: Immutable Offline Canary Cohort Receipts

Status: STAGED, NOT CERTIFIED. This is a post-PR #541 candidate for the next exact-head CI carrier.

## Implementation boundary

- `ExperimentAssignments` retains the canonical plan, consent/eligibility and assignment contracts.
- `BoundedAutonomyOfflineCanaryReview` calls an independently supplied verified, frozen aggregate source. The default source denies.
- `DatabaseBoundedAutonomyCanaryReviewReceipt` requires current experiment read permission and validates the canonical active workspace/brand plan hash, distinct authorized plan approver, independent aggregate result and immutable source fingerprint before recording evidence.
- One unique `workspace_id + experiment_id` row prevents repeated insert or positive evidence mutation. Conflicting source fingerprint or denominators refuse replay, while identical replay returns the original row ID.
- The database migration refuses destructive rollback of nonempty rows. Feature tests cover absent sources, missing approval, cross-organization forgery, replay and changed evidence.

## What the receipt does NOT do

The receipt does not assign a unit, enroll a canary, change a campaign, create a provider action, verify a live conversion, spend money, promote a candidate or issue permission. Default-deny independent sources prevent synthetic successes being mistaken for live evidence.

## Remaining TASK-0091 gates

- Independent evaluation/aggregate provenance must be anchored to truthful observable signals and current consent, not unverified AI text.
- Build versioned, owner-reviewed offline promotion decision and conservative duplicate/late/unknown provider-effect rollback evidence.
- Exact-head and resulting-main CI and adversarial PostgreSQL integration before certification.
