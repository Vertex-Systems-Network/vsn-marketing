# TASK-0091 — Offline Immutable Human Canary Decision Readback

Status: **staged only**, not merged or certified. No provider/campaign authority.

This slice builds on PR #546's deny-by-default exact-bound human promotion review and adds a durable, append-only decision table and independently permission-rechecked read source.

## Controls

- Decisions are scoped by workspace, brand and experiment, ordered by immutable per-experiment sequence. No migration seeds a grant, approval or human identity.
- Human session attestation and its SHA-256 evidence are required, but a **human-authenticated writer is not yet connected**. A row cannot be treated as production approval based on flags alone.
- On every read, the source checks a current approved/frozen experiment and the approver's **current** independent `ai.approve` and `campaign.approve` role membership.
- Revocation supersedes earlier approval; missing, foreign, expired (checked by downstream review), spoofed, unauthorized and self-decisions hold closed.
- The offline reviewer still reports `promotion_authorized=false`, `execution_authorized=false` and `external_outcome_proven=false`.

## Evidence and next gates

Feature tests cover absent evidence, missing each role permission, permission removal, foreign organization, fabricated session evidence, inactive experiment, newest revocation and self-approval. PostgreSQL schema and application tests require full exact-head CI and resulting-main verification before acceptance.

Remaining: independent authenticated append-only decision writer (human session only), atomic promotion-side policy and provider source provenance, conservative rollback/callback reconciliation, and TASK-0091 formal acceptance. No real provider integration is claimed.
