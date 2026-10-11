# TASK-0091 — Offline provider capture chronology

Status: **implementation candidate; exact-head and protected-main CI required before acceptance**.

## Evidence and failure mode

The dual-source rollback review accepted independently verified non-effect snapshots when the aggregate observation followed the latest *attempted-at* time. However, the provider attempt source also includes an independently bound `captured_at_unix` in its source checksum. A newer provider readback can surface late callbacks, costs, or changed status after an earlier aggregate non-effect observation. The former guard did not compare these capture clocks, so a stale aggregate could still produce a positive offline review candidate.

## Bounded change

- Expose the already schema-validated, manifest-bound attempt-source `captured_at_unix` in the **positive offline evidence response**. No provider API or external contract is changed.
- Keep the existing latest individual attempt chronology check.
- Additionally hold when the aggregate independently observed non-effect predates the provider evidence *capture* (reason `aggregate_review_predates_provider_capture`), or the capture time is missing or untyped.
- Add a regression proving that two individually valid, correctly hashed sources are held when aggregate observation predates a later provider capture, even though every underlying attempt predates the aggregate. Re-observing the aggregate after capture restores only offline human-review eligibility.
- Preserve all `rollback_performed`, `refund_authorized`, `retry_authorized`, `execution_authorized`, and `promotion_authorized` flags as `false`.

## Scope and limitations

This is a conservative ordering invariant on two offline independently sourced reviews; a checksum cannot authenticate a provider or guarantee a real-world non-effect. Human approval, expected-operation coverage, production-representative evidence, other stop/consent/quota gates, and separately authorized effectful provider actions remain outside this change. No live sending, budget consumption, retry, refund, rollback or promotion is enabled. TASK-0091 remains IN_PROGRESS pending full holistic acceptance.
