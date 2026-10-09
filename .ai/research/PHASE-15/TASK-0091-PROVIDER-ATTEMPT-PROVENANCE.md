# TASK-0091 — Independent Provider Attempt Provenance (Offline Slice)

This document describes the additional independent evidence check staged after PR #550. It is **not** a production connector, canary promotion authorization, irreversible rollback, provider refund, or evidence of a live campaign.

## Input authority

The only source is `BoundedAutonomyVerifiedProviderAttemptSource` implemented by a *separately trusted* adapter. The default implementation returns no evidence and therefore holds. An AI-generated string, agent plan, untrusted callback, analytics dashboard metric, or locally computed checksum does **not** establish provider identity or authority.

A source adapter must verify every provider receipt and idempotency key against provider-side records, ensure the list is complete for the requested tenant/run, and attach a fresh immutable attempt manifest. The local SHA-256 manifest detects accidental or malicious **in-process** field mutation after source verification; it is not a signature, a proof of external origin, or a substitute for independent readback.

## Deterministic failure classification

| Evidence | Operator-only result |
| --- | --- |
| Missing source, zero records or incomplete list | Hold; provider outcome unknown |
| Missing/foreign run, tenant or immutable snapshot | Reject |
| Unbound manifest, changed expiry/completeness, malformed records | Reject |
| Duplicated operation IDs or idempotency keys | Hold; duplicate provider attempt |
| Unverified or unknown provider attempt | Hold; reconciliation required |
| Confirmed applied or irreversible outcome | Hold; manual recovery |
| Late/duplicate callback | Hold; external state uncertain |
| Nonzero external provider cost | Hold; independent settlement |
| All individually verified, unique, timely confirmed-non-applied attempts | **Offline no-effect review candidate only** |

Every result sets `rollback_performed=false`, `refund_authorized=false`, `retry_authorized=false`, `execution_authorized=false`, and `promotion_authorized=false`.

## Acceptance limits

This is an additional TASK-0091 AC-3 component. It does not complete TASK-0091 or activate TASK-0092. Real adapter integration, proof of source authority and final exact-head and protected-main tests remain separate gates.
