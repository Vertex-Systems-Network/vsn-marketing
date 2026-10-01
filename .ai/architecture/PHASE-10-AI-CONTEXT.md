# PHASE-10 isolated context boundary

TASK-0056 currently assembles only explicitly selected, workspace/brand/customer/run scoped records. Null scope dimensions are exact null matches, never wildcards. Authenticated `TenantContext` and server-side permission checks determine eligibility. Repository retrieval uses all scope dimensions, active/deletion/expiry predicates and a 16-record bound; the assembler repeats scope, classification, permission, revision, provenance, expiry and byte checks before returning a sorted manifest and SHA-256. Text is separately labeled `untrusted_data`; neither source content nor the model grants tool or route authority.

The sanitizer rejects oversized/invalid UTF-8 content and recognizable email, phone and credential patterns. This is a conservative guard, not a claim that arbitrary personal data can always be detected. Only `public` or explicitly `approved_non_personal` records are eligible. No provider adapter is enabled, and the gateway still withholds output. A future provider activation requires account-specific retention/residency evidence and a proven upstream approval/redaction path.

## Migration and data-safety review

- **Idempotency:** migration registry controls the additive table; UUID primary key rejects duplicate source records. Re-run only after inspecting registry and schema.
- **Transactions:** PostgreSQL DDL is transactional; a memory delete atomically scrubs `content` and marks `deleted_at` in one update.
- **Apply/marker recovery:** inspect table and migration marker before retrying; never drop populated provenance to fix a marker.
- **Retries:** reads are bounded and deterministic; deletion is idempotent from the caller's perspective and returns false on already deleted rows. No provider call occurs in a DB transaction.
- **Rollback/restore:** `down()` refuses a populated table, including tombstones. Export and a verified restore plan are required for a populated rollback.
- **Destructive recovery:** no truncate or bulk deletion is performed. A scoped delete scrubs one row's content while retaining provenance metadata.
- **Concurrency:** database predicates bind every scope dimension and deleted/expiry state. A fresh query rechecks authorization and expiry; no cross-worker cache is assumed.
- **Partial execution:** missing, expired, deleted, over-budget or unsafe source makes the whole assembly fail closed; no partial context packet is returned.
- **Backup/snapshot:** the empty additive table needs no data migration. Before a future production migration or populated rollback, verify a snapshot under deployment policy. No production migration was executed here.
