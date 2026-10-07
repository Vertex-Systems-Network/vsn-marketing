# TASK-0080 additive provider-engagement migration review — 2026-10-08

Migration: `2026_10_08_000001_create_provider_engagement_facts.php`.

Scope is repository-side derived analytics evidence only. It creates one additive table and does not rewrite canonical customer events, provider/publication state, contacts, consent history or existing analytics facts. This review does **not** authorize production migration execution.

- **Idempotency / partial apply:** an existing table is accepted only when the full expected column set exists; an unknown partial schema fails closed.
- **Transactions:** PostgreSQL migration DDL remains managed by the Laravel migrator. Application admission additionally locks the canonical workspace row and uses a retry-bounded database transaction.
- **Replay/conflict:** deterministic `source_key` owns source identity. Exact replay returns `replayed`; changed evidence under the same source identity returns `conflict` and does not overwrite the accepted row.
- **Privacy:** raw provider lineage is never persisted; only SHA-256 lineage/source keys and non-identifying aggregate fields are stored. Individual/contact analytics remain on the existing consent-gated canonical event path.
- **Retention:** every admitted aggregate receives an approved analytics-purpose expiry and reads recheck current analytics authority/purpose.
- **Rollback/restore:** rollback refuses a non-empty table. Production rollback therefore requires an explicit backup/restore/destructive-data decision outside this repository batch.
- **Concurrency:** workspace row serialization plus the unique source key prevents competing duplicate insertion on PostgreSQL.
- **External authority:** no provider API call, credential use, live publication, deployment or production migration is introduced or authorized.
