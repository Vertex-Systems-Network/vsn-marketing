# TASK-0092 — Offline Red-Team and Incident Monitoring Baseline

Status: **AC-1/AC-3 first implementation slice; not task completion**. This pack records a scoped implementation hypothesis and limitations.

## Current external risk references (2026-10-12 research)

- OWASP GenAI LLM Top 10 project: <https://owasp.github.io/www-project-top-10-for-large-language-model-applications/>. Relevant threat classes are prompt injection, insecure output handling, sensitive information disclosure, excessive agency and resource exhaustion. Treat OWASP taxonomy as a threat source, not proof that this application is protected.
- NIST AI RMF Generative AI Profile (NIST AI 600-1): <https://nvlpubs.nist.gov/nistpubs/ai/NIST.AI.600-1.pdf>. Use the profile's continuous monitoring, scoped evaluation, human review and documented-unmeasurable-risk principles. Synthetic unit fixtures cannot establish real incident outcomes or regulatory compliance.
- The preplanned VSN Marketing task explicitly requires goal hijack, indirect/untrusted data, tool misuse, self-approval, cross-tenant access, quota/stop races, replay/provider failure, exfiltration and metric gaming.

## First bounded implementation slice

- A new scoped `BoundedAutonomyRedTeamEvidenceSource` interface returns **only synthetic offline test/incident evidence** and its default adapter denies everything. There is no provider/queue bridge.
- `BoundedAutonomyOfflineRedTeamIncidentReview` validates strict envelope/record schemas, tenant/actor/run identity, time freshness, bounded 128-case inventory, unique case IDs, risk category, severity and only the outcomes `blocked`, `held` or `unresolved`.
- A canonical hash commits to ordered test fixtures including timestamps, scope and outcomes; tampering under the old checksum fails. This hash is not a signature or proof of trusted external provenance.
- Raw prompts, credentials, email addresses or other attack payloads are rejected as unsupported fields and never emitted. Positive output returns only category/severity counts and an immutable manifest reference for **human triage**.
- Missing, incomplete, unknown/unresolved, stale, malformed, cross-tenant or tampered evidence cannot report recovery, a recorded production incident or any execution/promotion authority.
- Unit regression fixtures exercise the existing `BoundedAutonomyPreview` typed-tool/goal policy for goal hijack, untrusted instruction fields, escalation, unverified outcomes and cross-tenant attempts. They never contact an actual model, provider or live user.

## Explicit incomplete obligations

- **AC-1 not yet complete:** extend adversarial coverage to AI typed-tool approval/actor replay, deeper tool-result poisoning, internal-source provenance and collaboration/agent-collusion scenarios.
- **AC-2 not yet complete:** PostgreSQL race/stress fixtures for per-minute quota, global/workspace stop versus admission, approval revocation, double-claim, stale provider callbacks and deliberate queue failure under multiple workers.
- **AC-3 not yet complete:** append-only tenant-scoped incident audit, durable evidence retention/size bounds, investigation status and human recovery workflow. The current reviewer is *read-only* and explicitly returns `incident_recorded=false` and `recovery_performed=false`.
- Only separately authorized, trusted provider telemetry could justify a future production-monitoring claim. Do not infer production sending, money spent, breach, refund, rollback, incident closure or recovery from any fixture.

## Local coverage hardening after PR #570

An incoming synthetic envelope's `complete=true` is insufficient by itself. A review-ready result now requires at least one validated, non-unresolved finding in each of the nine registered adversarial categories. Missing categories produce `held_offline/redteam_coverage_incomplete` with zero reportable case counts. This checks fixture coverage only; it does not certify that each attack was effectively resisted, that provider/queue races were exercised, or that real incidents were recovered. The existing permanent audit hold remains in force after a prior incomplete or unresolved record.

Require exact-head Application/PHP/PostgreSQL/E2E/Security/Governance and protected-main verification for this carrier. This file does **not** mark any TASK-0092 acceptance flag done or alter the canonical progress percentage.
