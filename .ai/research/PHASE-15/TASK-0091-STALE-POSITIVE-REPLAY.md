# TASK-0091 — Stale Positive Rollback Review Replay

Status: **staged offline-only guard; exact-head and protected-main certification pending**.

## Confirmed replay vulnerability

The append-only rollback review ledger deduplicated a `review_fingerprint` before checking the latest durable event for that run. If independent evidence first returned `offline_rollback_review_ready`, then a later outcome revealed an irreversible provider effect or uncertainty, replaying the **old** positive fingerprint could still return the earlier positive status. No provider rollback was executed, but stale readback could mislead downstream human decisions.

## Fail-closed behavior

The recorder locks the canonical workspace, now reads and validates the latest run event **before** deduplication, and ensures unchanged actor, brand and plan identities. Replaying a historic positive review whose newer durable row is `held_offline` returns that most recent hold as a replay, rather than the old positive. The two historical events stay immutable, event count unchanged, and rollback/refund/retry/execution/promotion authorization always false.

New feature regression records (1) valid offline non-effect, (2) independently observed applied/irreversible outcome, (3) a verbatim replay of the original positive fact. It asserts the returned row is the newer held event with sequence 2; no third row or mutation is created.

## Limitations

This hardens **offline evidence readback only** and does not independently attest provider callbacks, implement external rollback, grant refunds or resolve previous uncertainty. TASK-0091 acceptance criteria remain incomplete pending complete independently trustworthy evidence and guarded certification.
