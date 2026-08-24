# PHASE-03 — Provider Framework, Security Baseline, and Initial Adapters

## Purpose

Establish the provider/channel abstraction and initial reference adapters without allowing the core to become email-only or provider-owned. Before external integrations become production-capable, close the audit security/supply-chain prerequisites and formalize current provider/API constraints through the Research-First Gate.

## Entry conditions

PHASE-03 may activate only when:

1. PHASE-02 remains certified and its acceptance evidence is intact.
2. TASK-0013 is transactionally activated from the canonical reconciliation state.
3. The Research-First Standard is accepted for the active work.
4. Any architecture change required by ADR-0002 is accepted before implementation relies on it.

## In scope

- current provider/channel/API market and official-document research;
- provider capability/connection/quota foundation;
- secret-reference authentication lifecycle;
- adapter contracts;
- normalized provider error/event semantics;
- rate/quota discovery interfaces;
- webhook authenticity/replay/idempotency contracts;
- API version/deprecation metadata;
- repository security/supply-chain hardening required before external integrations;
- initial reference connectors selected by current research;
- connector contract/sandbox/security/tenant tests.

## Explicitly out of scope

- PHASE-04 delivery routing/failover engine beyond contracts required by provider adapters;
- PHASE-05 production sender-domain/deliverability policy;
- PHASE-06 content/template/asset implementation;
- PHASE-07 campaign/social publication implementation;
- PHASE-10 product AI runtime;
- PHASE-13 broad omnichannel/social/community implementation;
- PHASE-14 generated connector activation.

Capability contracts may anticipate these later phases, but their product implementation must not be pulled forward.

## Permanent invariants

- Core/application modules depend on canonical provider capabilities, never provider-name conditionals.
- External IDs are references, never canonical identity.
- Credentials remain secret references.
- Webhooks normalize into canonical events.
- Retries are idempotent and classify permanent versus retryable failure.
- Provider fallback never bypasses consent, suppression, authorization, approval, region, budget, or risk policy.
- Social/messaging capability metadata must be granular enough to model platform restrictions rather than a generic `supports_social` flag.
- Research evidence precedes implementation.

## Task chain

| Task | Weight | Purpose |
|---|---:|---|
| TASK-0013 | 15 | Research/reconcile provider/channel architecture, audit findings, ADR-0002 and full preplan |
| TASK-0014 | 15 | Harden repository security and software-supply-chain governance |
| TASK-0015 | 20 | Canonical provider capability/connection/quota and secret-reference foundation |
| TASK-0016 | 20 | Adapter/error/webhook/quota/reconciliation contracts |
| TASK-0017 | 20 | Initial reference connectors plus sandbox/contract test matrix |
| TASK-0018 | 10 | PHASE-03 provider-neutrality/security/quality certification |

Weights total 100 for PHASE-03 progress calculation.

## Exit criteria

PHASE-03 may complete only when:

1. A current research pack documents official provider/API capabilities, auth/scopes, quotas/rates, webhooks, platform constraints, deprecations, sandbox behavior, and material market expectations for the selected reference providers.
2. Repository security/supply-chain controls required by TASK-0014 have measurable evidence or explicit approved exceptions.
3. Provider, ProviderConnection, ProviderCapability, and ProviderQuota are canonical workspace-safe concepts with external credentials referenced through the secret abstraction.
4. Core domain/application code contains no provider-name behavioral branching that belongs in connector capabilities.
5. Connector contracts normalize errors/events and explicitly model unsupported features.
6. Webhook authenticity/replay/idempotency and provider rate/quota behaviors are testable contract requirements.
7. Initial reference connectors pass authentication failure, success, transient/permanent failure, rate-limit, idempotency, webhook/malformed-webhook, capability, and sandbox tests where supported.
8. Cross-workspace provider connection/reference/credential metadata access fails closed.
9. Full applicable backend/integration/architecture/static/format/frontend/E2E/security/AI-continuity gates are green on the exact acceptance head.
10. No PHASE-04+ product capability is silently pulled forward.

## Research requirement

TASK-0013 must create the first phase research evidence pack under `.ai/research/PHASE-03/`. Subsequent PHASE-03 tasks must revalidate fast-changing external facts before their implementation begins.

## Exact continuation principle

PHASE-03 starts with governance/research. It does not start by adding a provider SDK.