# TASK-0078 community inbox migration review — 2026-10-08

Migration: `2026_10_08_000002_create_community_items.php`.

This migration repairs the previously incomplete TASK-0078 repository evidence with an additive, tenant-scoped Community inbox table. It does **not** authorize production migration execution or any provider-side operation.

- **Idempotency / partial apply:** a missing table is created once. An existing table is accepted only when the complete expected column set is present; an unknown partial schema fails closed.
- **Transactions:** schema application remains under Laravel migration transaction behavior for the active database. Community admission additionally executes inside a retry-bounded database transaction and serializes on the canonical workspace row before replay/conflict handling.
- **Replay / conflict:** `source_key` is a deterministic unique identity over workspace, provider and provider external item identity. Exact evidence replay returns `replayed`; changed evidence under the same source identity returns `conflict` without overwriting accepted evidence.
- **Privacy:** raw provider author identifiers and provenance URLs are not persisted. Only SHA-256 author/provenance/verification hashes plus operator-visible message body and bounded normalized workflow state are retained.
- **Tenant isolation:** workspace and optional brand ownership are persisted with foreign keys; all application reads/mutations reapply canonical workspace/brand scope and current permission checks.
- **Moderation / AI:** AI text is stored only as a proposal. Approval requires separate moderation plus `ai.approve` authority and does not call any provider or send a reply.
- **Inbound authority:** default composition intentionally has no `CommunityInboundVerifier`; inbound admission therefore fails closed until an explicit researched provider verifier is supplied.
- **Rollback / restore:** rollback refuses to drop a nonempty table. Production rollback therefore requires an explicit backup/restore/destructive-data decision outside this repository batch.
- **Concurrency:** workspace-row serialization plus the unique source key prevents competing duplicate first admission on PostgreSQL.
- **External authority:** no provider API call, credential use, webhook endpoint exposure, deployment, release, production migration or live response action is introduced or authorized.
