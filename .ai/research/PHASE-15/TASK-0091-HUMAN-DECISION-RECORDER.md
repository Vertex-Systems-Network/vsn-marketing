# TASK-0091 — Authenticated Human Canary Decision Recorder (Offline Only)

Status: **staged; not merged, not certified, no live authority**.

This candidate adds an internal append-only decision recorder for the durable readback introduced in PR #547. No HTTP route, provider credentials, campaign traffic or promotion executor is introduced.

The recorder requires the authenticated Laravel session user to equal the independent human approver, verifies current `ai.approve` **and** `campaign.approve` permission before and during a transaction, and rejects self-approval and unclassified outcomes. It acquires the canonical global stop before the experiment row and workspace quota; new approvals hold when global/workspace stop is enabled or policy expired.

A positive decision also requires re-running the independently sourced frozen-cohort-to-outcome join **inside the transaction** with exact plan and analysis provenance and conservative scoring. Absent a registered verified outcome source, the result is held and no human decision is inserted. A previous immutable decision may be rejected/revoked by a currently authorized human even while stopped. Revocation is append-only, retains immutable plan/outcome hashes, and duplicate revocation is replayed rather than duplicated.

All responses and recorded decisions remain `execution_authorized=false` and `promotion_authorized=false`. A session-bound SHA-256 audit digest is **not a cryptographic provider signature**, and does not prove any external conversion, rollback, refund or provider-effect success.

Feature tests cover missing session and privileges, absent stops, missing independent outcomes, expired quota policy, stop-tolerant revocation, replay, model-attempted auto-publishing, and self-approval. A synthetic independent-source **positive fixture** is now staged alongside the negative tests. It proves that the full frozen-cohort → verified-outcome → authenticated human decision path can record an offline decision without granting execution or promotion. Synthetic facts do not establish provider production provenance. This slice still needs full exact-head and protected-main checks before certification.
