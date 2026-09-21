# Last Checkpoint

## State

- Timestamp: `2026-09-21T21:44:51Z`
- Observed main: `b05153240dfe45cf7236b55b43acaf03394541e9`
- Active issue: `none`
- Active PR: `349`
- Active branch: `task/0038-scheduled-target-certification`
- Current milestone: `TASK-0038-SCHEDULED-TARGET-CERTIFICATION`
- Milestone status: `VERIFYING`
- Active task: `TASK-0038`
- Next task: `none`
- Current phase: `PHASE-07`
- Execution status: `in_progress`
- Pending Runner IDs: `RBT-015`
- Blocked Runner IDs: `RBT-004`
- State fingerprint: `c90022c34b99a4502c09c95e6942824f295bedb93df5c3635fbe66a8b2a62b73`

## Completed / observed this session

PR #349 stages the next bounded TASK-0038 product milestone from protected main `b05153240dfe45cf7236b55b43acaf03394541e9`. It adds governed Approved -> ScheduledIntent recording with exact latest-snapshot/current-approval revalidation, fixed-instant future scheduling validation, immutable intended-execution evidence, replay-safe command handling and an explicit no-scheduler-execution boundary.

Canonical recipient/target certification now requires ContactList/Tag bindings to pin `materialized_contact_ids`, normalizes materialized recipient ordering for stable target fingerprints, validates each materialized recipient in the same workspace and verifies exact current list/tag membership before the immutable snapshot is accepted. PostgreSQL/adversarial coverage includes ContactIdentity, ContactList and Tag success plus foreign-workspace recipient and membership-drift failures.

No schema migration, live provider API call, media upload, publication attempt, production scheduler execution, provider credential activation, deployment/release authority, TASK-0039 activation or deferred Runner optimization is introduced.

## Tests

Exact-head external CI is pending. RBT-015 is the merge-required continuity/application/security workload for PR #349. Focused tests cover scheduled-intent replay/provenance and canonical identity/list/tag materialization/isolation.

## Blockers

- None

## Exact next action

Perform one consolidated exact-head verification for PR #349. Merge the TASK-0038 scheduled-intent and canonical target certification milestone only if AI Continuity Guard, Application Foundation CI and Security Supply Chain CI are green, review is clean, and the exact head is unchanged. Treat scheduled-intent authority/timestamp/replay failures, stale approval inheritance, canonical materialized-set drift, target-hash instability, cross-workspace identity/list/tag access, transaction safety regressions, or any scheduler/provider side effect as merge blockers. After trusted merge, perform terminal durable reconciliation before starting another TASK-0038 milestone. Keep live provider publication, media upload, production scheduler execution, provider credentials, deployment/release authority, TASK-0039 and the deferred Runner benchmark batch inactive.
