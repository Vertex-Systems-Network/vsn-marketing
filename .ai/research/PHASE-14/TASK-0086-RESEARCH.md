# TASK-0086 Research Pack — Connector compatibility and lifecycle safeguards

- researched_at: 2026-10-08
- task: TASK-0086
- phase: PHASE-14
- scope: compatibility scoring, deprecation provenance, fail-closed lifecycle decisions and tenant-scoped audit evidence
- researcher: Supervisor

## Current authoritative sources

| Source | Finding and implementation impact |
| --- | --- |
| OpenAPI Specification 3.2.0 (https://spec.openapis.org/oas/v3.2.0.html), published 2025-09-19 | OAS distinguishes major/minor feature sets from patch clarifications; declared deprecated operations remain part of the specification. Preserve version lineage and treat deprecation as a signal rather than an automatic contract switch. |
| RFC 9745 (https://datatracker.ietf.org/doc/html/rfc9745) | The Deprecation response header communicates deprecation without changing resource behavior. Sunset can communicate expected unavailability; its date must not precede deprecation. Preserve observation time and source provenance; alert without silently changing a pinned contract. |
| RFC 8594 (https://datatracker.ietf.org/doc/html/rfc8594) | Sunset is a dated signal about expected resource unavailability, not authorization to automatically switch endpoints or activate a replacement. |
| OWASP API Security Top 10 2023 (https://api-security.owasp.org/editions/2023/en/0x11-t10/) | API inventory/version management and unsafe third-party API consumption support explicit version lineage and fail-closed handling of unknown changes. |
| NIST SSDF 1.1 (https://csrc.nist.gov/pubs/sp/800/218/final) | Secure development requires attributable, verifiable change and recovery practices. Lifecycle decisions therefore carry tenant, actor, evidence hash, UTC time and idempotency identity. |

## Threats and controls

- Provider docs can be stale or inconsistent. Record source URI, digest and observation time; never infer an unobserved API version.
- Unknown or incompatible contract changes score zero and block activation.
- Deprecation observations surface alerts without changing a pinned connector contract or silently upgrading it.
- Disable/rollback decisions are explicit, deterministic, attributable, tenant scoped and idempotent; rollback names an immutable candidate.
- Lifecycle evidence must not contain access tokens, secret values or unrelated tenant data.

## Decision

CONFIRMS_PLAN: implement deterministic policy/evidence contracts first, then add persistence, operator surfacing and failure reconciliation where existing provider repository boundaries support them. Keep provider activation disabled by default and do not perform live provider calls.

## Acceptance boundary

This pack records requirements and current source evidence. It does not claim provider-version coverage, production effectiveness, live polling, persisted tenant evidence, alert delivery or successful rollback execution. Those require implementation and exact-head test evidence.
