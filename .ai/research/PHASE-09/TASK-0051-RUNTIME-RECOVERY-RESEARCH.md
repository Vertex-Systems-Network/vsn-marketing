# TASK-0051 runtime recovery and provider boundary revalidation — 2026-09-29

## Scope

Revalidate the production wake-up/recovery path after the RBT-052 v6 controlled backlog capture and inspect whether a real provider action can safely be dispatched from a journey node.

## Current authoritative sources

- Laravel 12 queue documentation: <https://laravel.com/docs/12.x/queues> (retrieved 2026-09-29). Redis `retry_after` controls when an unacknowledged job becomes visible again; a worker timeout should be several seconds shorter to prevent concurrent processing. The queue connection's `after_commit` option delays dispatch until the DB transaction commits and discards the dispatch if the transaction rolls back. Unique queue locks are shared-cache coordination, not a replacement for durable business idempotency.
- Brevo transactional send API: <https://developers.brevo.com/reference/send-transac-email> (retrieved 2026-09-29). `POST /v3/smtp/email` is an external send operation; request fields include sender, recipients, content/template and headers. A `messageId` acknowledges request acceptance, not final delivery.
- Brevo batch idempotency: <https://developers.brevo.com/docs/heterogenous-versions-batch-emails> (retrieved 2026-09-29). `idempotencyKey` must be a UUID and the provider TTL is 30 minutes. Provider idempotency cannot alone cover VSN's full durable retry/recovery horizon.

## Repository observations

- `JourneyNodeJob` is a Redis wake-up job configured `afterCommit`; PostgreSQL `journey_work_items` and node-attempt leases are authoritative.
- `ProcessJourneyNode` marks a work item pending with `available_at = now + 1s` when the workspace concurrency budget refuses a claim, then acknowledges the wake-up job. Recovery is provided by `RedispatchDueJourneyWork`, which scans pending/running/waiting work in a workspace with a bounded limit.
- `routes/console.php` currently schedules only `outbox:dispatch`; it does not invoke `RedispatchDueJourneyWork`. Thus the recovery implementation has no production scheduler entry point, while the benchmark harness manually drives recovery sweeps.
- The journey action production binding is `RejectUnconfiguredJourneyAction`. Current provider `ConnectorAdapter` implementations expose manifests only; they do not implement a send operation. Journey worker context also does not carry an authorized actor, and there is no journey action authorization/quota evaluator or provider-secret resolver in the action path.
- RBT-052 v6 run `36556234322` shows 200 jobs/four workers completing in two passes with 2.774s p95 queue age; eight workers on the same 200-job fixture took five passes and 64.266s p95. This is isolated-harness evidence and does not authorize or establish production worker limits.

## Classification

- `CONFIRMS_PLAN`: Add a scheduled bounded recovery entry point using the existing workspace-scoped recovery service. Preserve PostgreSQL as source of truth, dispatch through the existing Redis wake-up job (`afterCommit`), and rely on lease/idempotency fencing for duplicate wake-ups.
- `BLOCKER`: Keep production provider actions fail closed. A production sender would need an authorized action principal, registered connector dispatch contract, secret resolution, fresh consent/suppression/quota/authorization checks, canonical error mapping, durable unknown-outcome handling, and provider-specific idempotency semantics. Provider calls and provider credential/sandbox validation are not performed by this work.
- `NO_PRODUCTION_LIMIT`: Do not derive a production concurrency setting from the 2-CPU harness comparison. The worker-count result guides future benchmark hypotheses only.

## Safe implementation slice

Create a scheduled Artisan command that enumerates distinct workspaces with due `pending`, expired `running`, or due `waiting` journey work, calls the existing bounded redispatch service once per workspace, and reports counts without exposing payloads. Schedule it on one server with overlap prevention. Add tests that prove due-only selection, workspace isolation, bounded dispatch, and scheduler registration. Keep task AC-5 blocked until representative production-action evidence and all remaining task criteria are independently satisfied.
