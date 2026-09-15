# PHASE-05 — Domains, Sender Identity, Suppression, and Deliverability

Status: **PLANNED — TASK-0025 research staged; no PHASE-05 production capability active yet.**

## Purpose

Make permission-based sending operationally safe, reputation-aware, provider-neutral, and jurisdiction-aware without allowing deliverability optimization to override consent, suppression, authorization, or provider policy.

## Preplanned task sequence

1. `TASK-0025` — Research current deliverability, sender-authentication, provider, and jurisdictional requirements.
2. `TASK-0026` — Implement sender domains and sender identities.
3. `TASK-0027` — Implement suppression, unsubscribe/preferences, bounce, and complaint processing.
4. `TASK-0028` — Implement frequency caps, reputation/health signals, and safe sending policy.
5. `TASK-0029` — Implement deliverability observability and bounded remediation recommendations.
6. `TASK-0030` — Certify PHASE-05.

Only TASK-0025 may be activated by the next guarded task transition. Later tasks remain non-executable until their dependencies and research gates are satisfied.

## Research-derived invariants

The dated evidence pack is `.ai/research/PHASE-05/TASK-0025-RESEARCH.md`.

- Sender authentication is evidence-oriented: SPF, DKIM, DMARC, alignment, DNS and TLS state remain individually observable/versioned rather than collapsed into one `verified` boolean.
- Provider bulk/high-volume rules are versioned provider policy. Google/Outlook volume semantics must not become a global constant, and no Yahoo numeric threshold may be invented where the official source does not publish one.
- Marketing/transactional purpose is explicit and auditable before delivery eligibility evaluation.
- Consent, suppression, objections and authorization remain deterministic authority. AI, provider failover, reputation scoring and admin convenience cannot override them.
- Applicable one-click unsubscribe follows RFC 8058 semantics and is testable independently of an authenticated browser session.
- Accepted unsubscribe/objection evidence becomes effective in VSN eligibility immediately; downstream provider synchronization is separate reconciled state.
- Jurisdiction/subscriber/relationship context is policy input. Missing or unsupported legal-policy context never silently becomes permission.
- Deliverability health signals may restrict or recommend but cannot create consent, erase suppression or justify anti-abuse evasion.

## Security and privacy boundaries

- No DNS/API secret material in canonical sender records; use secret/reference boundaries.
- No live DNS mutation during TASK-0025.
- No production sender-domain activation during research.
- No unrestricted bought/public-list permission inference.
- No fake-account rotation, spam-rate gaming, deceptive headers, warm-up abuse, provider-limit circumvention or suppression bypass.
- Cross-workspace sender identity, unsubscribe, complaint and reputation state must be isolated and adversarially tested.

## Provider and policy configuration model

Provider requirements are expected to be effective-dated configuration/data with provenance and explicit applicability. Provider-specific thresholds or enforcement rules do not belong as scattered constants in core business logic.

Jurisdiction policy must be capable of expressing at least: jurisdiction, subscriber/person type where applicable, solicitation/customer relationship basis, consent/soft-opt-in evidence, message purpose, sender identity, suppression/objection state, effective date and review/unknown outcomes.

This phase document does not itself make legal determinations for unsupported jurisdictions. Country/member-state policy activation remains evidence/review gated.

## Phase completion evidence

PHASE-05 cannot be certified merely because sender-domain UI or provider records exist. TASK-0030 must prove at minimum:

- zero suppression/objection bypass in adversarial tests;
- cross-workspace isolation of sender/suppression/reputation state;
- provider-versioned authentication and bulk-sender policy behavior;
- RFC 8058 one-click correctness where applicable;
- bounce/complaint/unsubscribe replay and reconciliation safety;
- frequency/reputation policy fail-closed behavior;
- deterministic distinction between permission authority and deliverability recommendation;
- exact-head continuity, backend/integration, static-analysis, security/supply-chain and applicable browser acceptance gates.

## Explicitly out of scope

- PHASE-06 canonical content/template/asset implementation;
- PHASE-07 campaign/publishing engine;
- PHASE-09 journey engine;
- later AI-autonomy capability;
- production DNS/provider activation before the corresponding task and operational approvals.
