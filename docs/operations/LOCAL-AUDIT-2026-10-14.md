# VSN Marketing local audit — 2026-10-14

Baseline: protected main `4bf8b56bc2ac7ce6486d26d715487fd55ed4828b`; existing Phase 16 draft PR #573. Historical roadmap completion (96%) describes bounded offline certifications, not deployable production readiness.

## Verified local installation

Node 24.19.0, PHP 8.3.6, locked Composer and npm dependencies, SQLite local database with all migrations, frontend production bundle, Laravel HTTP server. PostgreSQL18 and Redis8 remain canonical acceptance runtimes. Local PostgreSQL execution is unavailable in this single-UID environment; Chromium is installed but its Unix socket operation is denied by the host runtime. These are verification limits, not passing checks.

## Repairs

- Brand update HTTP requests require `expected_revision`; stale writes return 409 and preserve the latest settings and audit. Locking the parent brand also serializes first creation on PostgreSQL. The UI reports conflicts and retains unsaved edits.
- Malformed SCIM Operations shapes are rejected with 400 before mutation, including scalar, null and incomplete input. The narrow deactivation adapter remains disabled by default.
- Merge the protected-main Phase 15 closure into the existing review carrier; preserve journal events and reconcile stale PR #572/publication pointers.

## Remaining implementation and evidence

| Priority | Task / area | Pending work |
| --- | --- | --- |
| 1 | TASK-0094 | Complete research acceptance with current official evidence and exact-head/resulting-main gates; later task drafts stay unaccepted. |
| 2 | TASK-0095 | Selected cryptographic OIDC/SAML adapter, complete SCIM contract, token issuance/rotation, session inventory/revocation, privileged governance workflow and PostgreSQL race tests. Proposed ADR-0005 requires review. |
| 3 | TASK-0096 | Verified regional inventory/routing across all copies, actual export/erasure/retention execution, legal-hold precedence and partial failure/recovery. |
| 4 | TASK-0097 | Full client hierarchy and delegated administration routes, scoped logo delivery, sibling-client browser and keyboard/accessibility evidence. |
| 5 | TASK-0098 | Plans, entitlements and metering integration, selected payment adapter and signed webhook/replay/concurrency evidence. No live charges authorized by this audit. |
| 6 | TASK-0099 | Real isolated encrypted backup/restore, independent key custody, approved RPO/RTO, measured loss/restore time, queues and external-effect reconciliation. Synthetic fixture decrypt is not disaster recovery. |
| 7 | TASK-0100 | Independent security/privacy/accessibility/supply-chain/performance/reliability/DR acceptance matrix and full protected-main gates. |
| Deferred | Email engagement extension | TASK-0101..0108 remain unweighted candidate work; research and explicit materialization required. |
| Deferred | Runner/provider production evidence | Existing RBT-004/RBT-052 and live provider activation need their separate canonical authority/evidence. |

## Test evidence

- Full PHP8.3 suite: 1,098 passed, 154 infrastructure skips, 7,492 assertions.
- PHPStan: no errors. Pint: passed. Architecture: 4 passed, 3,669 assertions.
- TypeScript, 50 Vitest tests and Vite build: passed.
- Current-head PostgreSQL18, Redis8, browser, security and governance CI: required before merge; local results do not substitute for those gates.

No Phase16 completion, live interoperability, supported residency region, legal compliance, actual restore or production activation is claimed.
