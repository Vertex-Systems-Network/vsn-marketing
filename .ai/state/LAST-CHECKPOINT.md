# Last Checkpoint

## State

- Timestamp: `2026-09-22T22:38:00Z`
- Observed main: `2db0b1fa3604731c9e8e908df592cf778baea2dd`
- Active issue: `none`
- Active PR: `365`
- Active branch: `task/0039-provider-schedule-capability-evidence`
- Current milestone: `TASK-0039-PROVIDER-SCHEDULE-CAPABILITY-EVIDENCE`
- Milestone status: `VERIFYING`
- Active task: `TASK-0039`
- Next task: `none`
- Current phase: `PHASE-07`
- Execution status: `in_progress`
- Pending Runner IDs: `RBT-024`
- Blocked Runner IDs: `RBT-004`
- State fingerprint: `926fa10667d8e3853543c4f1c0858e4c4516c83df7859b319c21c27fff5b29ea`

## Completed / observed this session

TASK-0039 AC-6 due-claim/execution-intent semantics were terminally reconciled through PR #364 on protected main `2db0b1fa3604731c9e8e908df592cf778baea2dd`. Exact reconciliation source `7cfff8d7d57dfe76e1ad292378529dc94fdad565` passed AI Continuity Guard `35792613078`, Application Foundation CI `35792613112`, and Security Supply Chain CI `35792613031`.

PR #365 stages the bounded AC-7 provider-schedule capability-evidence slice. VSN local timezone input and immutable resolved UTC remain canonical even when the provider evidence namespace contains `publication.schedule.remote`; provider capability constraints never rewrite canonical schedule time.

New scheduler work now revalidates current authority after a due claim exists: stale-lease takeover and new execution-intent emission reuse the canonical `CampaignApprovalEvaluator` and require the exact approval decision pinned by the claim to remain current. Connection readiness, scopes/roles/freshness, capability support/version/freshness and workspace binding therefore fail closed on drift before new intent/outbox work can be created. Already-committed immutable intent replay remains historical/idempotent and does not create duplicate work after later provider drift.

Canonical campaign JSON now rejects provider/external/remote/native schedule IDs and provider-native scheduled/publish timestamps in addition to existing token/credential/provider-payload/transient media guards. No provider API call, native schedule request, upload, publication attempt or credential activation is introduced.

TASK-0039 remains in progress at roadmap `48.45%` / PHASE-07 `63.64%`. RBT-024 is pending exact-head verification. After AC-7 terminal reconciliation, AC-8 final TASK-0039 acceptance/full certification is next before any TASK-0040 registration.

## Tests

PR #365 adds focused security coverage proving canonical VSN time with `publication.schedule.remote`, connection scope drift rejection before emission, capability support drift rejection before emission, unavailable-connection rejection before stale-lease takeover, immutable committed intent replay after later provider drift, and provider-native schedule transient-key rejection.

## Blockers

- None

## Exact next action

Perform exact-head verification for PR #365. Merge the TASK-0039 AC-7 provider-schedule capability-evidence milestone only if AI Continuity Guard, Application Foundation CI and Security Supply Chain CI are green on the unchanged head; review is clean; VSN fixed/queue calendar time remains canonical; `publication.schedule.remote` is evidence only; new stale-lease takeover and new execution-intent emission revalidate the exact pinned approval plus current same-workspace provider connection/capability authority; disconnect, scope/role revocation, stale/not-effective/unsupported/incompatible evidence and changed approval lineage fail closed without creating intent/outbox work; already-committed intent replay remains immutable and duplicate-free; provider-native schedule IDs/timestamps/payloads and credentials remain outside canonical campaign/schedule/claim/intent records; and no provider API call, upload or publication attempt is introduced. After trusted merge, terminally reconcile AC-7 and then stage TASK-0039 AC-8 final acceptance/full exact-head certification before any TASK-0040 registration.
