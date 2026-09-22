# PHASE-07 — Campaigns, Publishing, Approvals, and Scheduling

Status: **IN PROGRESS — TASK-0039 approval-timing/missed-occurrence history is staged on PR #361 under exact-head verification; due-claim execution and live provider scheduling/publication remain inactive.**

## Purpose

Orchestrate governed cross-channel campaigns and publication from one canonical VSN calendar while keeping provider-specific APIs, account roles, app-review requirements, media transfer mechanics and native scheduling semantics behind versioned capability evidence.

## Preplanned task sequence

1. `TASK-0037` — Research current campaign/social publishing APIs, app-review/scopes, scheduling constraints, media rules, and market calendar workflows.
2. `TASK-0038` — Implement campaign lifecycle, immutable snapshots, recipients/targets, approvals, and audit history.
3. `TASK-0039` — Implement unified editorial/campaign calendar and timezone-safe scheduler.
4. `TASK-0040` — Implement channel-neutral publication lifecycle and provider reconciliation.
5. `TASK-0041` — Implement campaign/publishing operator UX.
6. `TASK-0042` — Certify PHASE-07.

TASK-0037 and TASK-0038 are completed. TASK-0039 is in progress; PR #355 fixed-instant calendar/timezone foundation, PR #357 queue/next-slot rule foundation, and PR #359 append-only reschedule/cancel history are trusted. PR #361 stages approval-timing enforcement plus immutable missed_needs_reschedule occurrence history; due-claim worker concurrency/execution-intent emission remains a separate AC-6 slice.

## Staged research direction

The dated evidence pack is `.ai/research/PHASE-07/TASK-0037-RESEARCH.md`.

- VSN owns canonical campaign state, immutable publication snapshots, approvals and intended execution time. Provider objects are derivatives/references and never become canonical campaign authority.
- Provider-native scheduling is optional capability evidence, not a universal contract. YouTube and Facebook expose native scheduling semantics in reviewed official material; other reviewed posting surfaces are direct/upload/publish oriented and must not be assumed to expose an equivalent provider-side schedule field.
- App/account eligibility is provider-specific. Examples include TikTok client audit requirements, Instagram professional-account restrictions and changing scope names, LinkedIn organization roles/approved permissions, YouTube API audit restrictions, and X user-context write authorization.
- Media publication is often multi-step and asynchronous. Upload/container/media identifiers, processing states and expiration must be reconciled and must not replace canonical asset identity.
- Approval must bind to an exact immutable content/campaign snapshot. Any material edit after approval requires deterministic re-evaluation rather than silent reuse of stale approval.
- Calendar semantics distinguish fixed instant scheduling from queue/next-slot scheduling and preserve explicit channel timezone plus DST rules.
- Provider disconnect, permission loss, processing failure, missed approval deadline, partial publish and delayed/out-of-order status updates are first-class deterministic states, not generic success/failure booleans.
- Existing consent, suppression, sender safety, workspace isolation, security and provider-neutral boundaries remain authoritative during PHASE-07.

## Security and privacy boundaries

- No provider access token, refresh token, signing secret or credential material in campaign/calendar canonical records.
- No cross-workspace target account, campaign snapshot, approval, schedule or publication-attempt references.
- Remote media pulls must use allowlisted/verified canonical asset URLs and SSRF-safe boundaries; provider fetch requirements never authorize arbitrary network access.
- Publishing attempts are idempotent and reconciled; retries cannot silently duplicate external posts.
- App-review/audit or account-role restrictions are fail-closed capability evidence.
- Live provider posting is forbidden during TASK-0037 research.
- The persistent Runner benchmark backlog remains independent and deferred; required correctness/security gates may run under its documented blocker exception, but no optimization batch is activated by this phase transition.

## Phase completion evidence

TASK-0042 must ultimately prove at minimum:

- approval cannot be bypassed and stale approvals cannot authorize changed content;
- canonical snapshots and target/account identities are immutable and workspace-isolated;
- timezone/DST, reschedule, cancellation and missed-run behavior are deterministic;
- duplicate prevention/idempotency survives retries, webhook duplication and reconciliation;
- provider capability/version drift and permission loss fail closed;
- partial multi-channel success/failure is observable and recoverable without rewriting history;
- provider media processing and temporary identifiers are reconciled without becoming canonical authority;
- PostgreSQL-backed browser flows, accessibility, performance and full security/supply-chain gates are green.

## TASK-0038 registration boundary

TASK-0038 is active only for the provider-neutral campaign lifecycle/snapshot/target/approval/audit implementation contract. It does not authorize live provider posting, production scheduling workers, provider credential activation, deployment/release execution or Runner benchmark batch execution. Any schema migration introduced by TASK-0038 must satisfy the repository migration/data-safety contract before merge or execution.

## Explicitly out of scope for TASK-0037

- live campaign creation or publication against provider accounts;
- production scheduling workers or calendar execution;
- provider credential activation;
- PHASE-08 segmentation compiler;
- PHASE-09 journey runtime;
- autonomous AI publishing;
- Runner benchmark batch execution.

## TASK-0039 registration boundary

TASK-0039 owns the canonical workspace-scoped editorial/campaign calendar and provider-neutral scheduler semantics: fixed-instant versus queue/next-slot strategies, IANA timezone resolution, deterministic ambiguous/nonexistent DST policy, immutable resolved execution instants, append-oriented reschedule/cancel/missed-run history, approval timing enforcement, and concurrency/idempotency-safe due claiming. Provider-native scheduling remains optional versioned capability evidence; TASK-0039 does not authorize live provider API calls, media upload/publication attempts, provider credential activation, deployment/release execution, TASK-0040 activation or deferred Runner optimization.
