# TASK-0100 enterprise certification matrix — 2026-10-16

Status: **CERTIFIED WITHIN ADR-0006 BOUNDARIES**. Evidence is repository tests executed by exact-head protected CI (SQLite application suite, PHP 8.3 floor, PostgreSQL/Redis integration, browser E2E, security supply chain, governance) and resulting-main runs. Production activation of any external provider, region or live payment is not claimed.

| Gate | Evidence | Boundary |
| --- | --- | --- |
| Security and identity | TASK-0095 (PR #579, #584): pinned OIDC verifier, local IdP adversarial interop, replay store, governed subject binding, SCIM deactivation and credentials, session revocation, governed roles | SSO/SCIM disabled by default; real IdP activation needs owner decision |
| Privacy and residency | TASK-0096 (PR #585): eight-surface EU-only admission, legal holds, export, anonymizing erasure, retention | Residency is configuration-attested; unset surfaces fail closed |
| Tenant isolation | Cross-workspace negatives in federation, SCIM, audit, privacy, agency (sibling clients), billing (webhook tenant mismatch) and recovery (authenticated tenant binding) suites; PostgreSQL integration job | — |
| Delegation and white-label | TASK-0097 (PR #586): non-delegable privileged permissions, spoof-proof branding | — |
| Billing | TASK-0098 (PR #587): idempotent metering, fail-closed entitlements, two-person plan changes, verified test-mode webhooks | Live charges hard-disabled |
| Reliability and DR | TASK-0099 (PR #588): encrypted backup/restore, partial-failure rollback, lost key, replay, external-effect reconciliation, measured exercise vs RPO 24h/RTO 4h | Exercised in CI environments; no production backup target claimed |
| Accessibility | Role-based tests: enterprise overview, workspaces, login, brand preview; E2E operator flows | Automated checks only |
| Supply chain | security-gates, dependency-audit, secret-scan, container-scan, SBOM reproducibility, CodeQL, action integrity on each carrier | — |
| AI governance | Existing default-deny AI route/region admission; AI egress surface fails closed unless EU-attested | No live model provider enabled |
| Performance | Bounded pagination, indexed tenant lookups, chunked restore inserts | No production SLO claim |

AC-2: unsupported regions, providers, live charges and production claims remain disabled (config defaults, `createLiveCharge()` refusal, disabled SSO/SCIM). AC-3: certified by the exact-head and resulting-main gates of the certification carrier.
