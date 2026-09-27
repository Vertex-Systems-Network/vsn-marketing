# ADR-0004 — Durable journey attempt retries and cancellation

Status: **Accepted for PHASE-09**
Date: 2026-09-27

## Context

Journey node workers can overlap, lose their lease, or observe a provider timeout after the provider may already have applied a side effect. A retry must not let an old worker overwrite the new owner or blindly repeat an action with an unknown outcome.

## Decision

- Each attempt uses the deterministic key derived from execution, node, and attempt number. A workspace-scoped lease token fences completion and failure writes; lease expiry permits recovery with a new token.
- Execution transition revisions append to a workspace-scoped immutable transition ledger in the same transaction as the state change. Workspace row locking serializes claims so configured concurrency budgets cannot be exceeded by parallel workers.
- Retry budgets, lease durations, and delay bounds are validated fail-closed. Retryable outcomes may be rescheduled only within the configured attempt budget. Exhausted/non-retryable outcomes become dead letters; unknown side-effect outcomes go to operator review and are never blindly retried.
- Cancellation is workspace-scoped, durable, and terminal for the execution. It clears active lease tokens; later worker completion is rejected. External action handlers remain responsible for propagating the attempt key as their provider idempotency key.
- Stored error metadata is allowlisted and bounded. Raw exception messages, payloads, and credentials are not persisted as attempt diagnostics.
- Executions continue to reference their immutable journey version. Replay must create a separate explicitly authorized operation that pins that same version and records a new execution identity; this repository slice does not silently replay or re-evaluate newer drafts.

## Consequences

Expired leases can be reclaimed safely, and stale holders cannot commit over a newer lease. Operators receive an explicit review state for ambiguous side effects. Production concurrency, rate, and latency thresholds remain unclaimed until representative benchmark evidence and owner approval exist.
