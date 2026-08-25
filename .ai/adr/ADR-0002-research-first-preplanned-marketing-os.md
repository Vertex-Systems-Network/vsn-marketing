# ADR-0002 — Research-First Preplanned Marketing OS Architecture

- Status: Accepted
- Date proposed: 2026-08-25
- Date accepted: 2026-08-25
- Governing task: TASK-0013
- Evidence: `.ai/research/PHASE-03/TASK-0013-RESEARCH.md`
- Scope: Research-first development governance, full future roadmap preplanning, audit remediation, social/creative operating boundaries, and quality engineering gates

## Context

PHASE-02 is certified and TASK-0013 is now the active PHASE-03 research/reconciliation task. The repository already defines VSN Marketing as an AI-native, provider-agnostic marketing operating system rather than a Mailchimp clone, but the original architecture was substantially stronger for email/customer automation than for social publishing, creative asset operations, community workflows, and continuously researched external constraints.

The post-PHASE-02 audit also identified governance and quality gaps that must remain explicit roadmap work rather than informal observations: branch/ruleset enforcement, security and secret/dependency scanning, software-supply-chain provenance and immutable CI dependencies, PostgreSQL-backed critical paths, performance/SLO/load gates, accessibility and visual regression, disaster-recovery evidence, adversarial AI evaluation, deterministic context completeness, and uniform phase/acceptance traceability.

TASK-0013 then performed current official-source research across delivery providers, marketing/mailbox APIs, social publishing platforms, security standards and market-reference products. The evidence confirms that provider behavior is too volatile and multi-dimensional for provider-name branching or boolean capabilities. Authentication, quota, webhook, app-review, account-role, media, disclosure, version and sandbox semantics must be expressed through canonical contracts and connector metadata.

## Decision

### 1. Research-First Gate is permanent governance

Before implementation begins for any new subsystem, provider, channel, AI capability, security-sensitive integration, major dependency, user-facing market category, regulated feature, or significant infrastructure mechanism, the active task must produce or revalidate a dated research evidence pack following `.ai/11-RESEARCH-FIRST-STANDARD.md`.

Research prioritizes current authoritative sources: official API/provider documentation, standards bodies, security guidance, platform policies, release notes, and relevant current market/reference implementations.

Research is evidence, not permission to silently rewrite history. When evidence finds a missing prerequisite or materially safer/better requirement, the roadmap/task/ADR must be explicitly extended or reconciled before implementation.

### 2. Full preplanned implementation skeleton remains the minimum known plan

`.ai/roadmap/PREPLANNED-IMPLEMENTATION-PLAN.md` reserves TASK-0013 through TASK-0100 as the current long-horizon skeleton.

The skeleton is not an authorization mechanism. `CURRENT-STATE.yaml`, machine task files, dependencies, accepted ADRs, research evidence and phase activation still control execution.

Research may append justified work or split unstarted work with traceability. It may not delete or weaken requirements merely because external reality makes them difficult.

### 3. Channel-neutral marketing operating model

Provider abstractions must not become email-only. Canonical contracts must support delivery providers, marketing platforms, mailbox APIs, messaging channels, social publishing/community APIs and future channels without provider-name conditionals in core business logic.

The following stable module boundaries are accepted and shall be added to the canonical module registry without renumbering existing modules:

- `Assets` — canonical media/creative asset ownership, variants, metadata, provenance, rights and transformations.
- `Publishing` — channel-neutral publication drafts, targets, schedules, attempts, provider status reconciliation, supported edit/delete/cancel semantics and publication analytics references.
- `Community` — normalized comments, mentions, conversations, inbox assignments, moderation/response proposals and listening/community signals where APIs permit.

These boundaries do not authorize their feature implementation in TASK-0013. Assets remains planned in PHASE-06, Publishing in PHASE-07, and Community in PHASE-13.

Social networks remain connectors/adapters. Core business logic must not import concrete social SDKs.

### 4. Provider capability and readiness are distinct

A provider may technically expose an operation while the current connection is not eligible to execute it. The provider framework must therefore distinguish:

- declared capability;
- connection/authentication state;
- granted scopes/roles;
- account/profile type;
- provider/app review or audit state;
- environment/access tier such as sandbox/trial/private-only/production;
- region/project/account/user scope;
- quota/capacity state;
- policy/approval requirements;
- API version/deprecation state.

Unknown or stale metadata is not treated as supported/ready.

### 5. Quotas are multi-dimensional and refreshable

Provider quota/rate metadata must support provider-specific units, windows, scopes, endpoints/categories, regions, tiers, current usage/remaining/reset data and dynamic discovery when available.

Current research proves that a flat integer cannot safely model SES recipient-based rolling regional quotas, Brevo simultaneous RPS/RPH endpoint tiers, Gmail per-user/per-project quota units, or rapidly changing social/API quota buckets.

Volatile limits are references with source/freshness metadata, not permanent product truth.

### 6. Webhook authenticity is strategy-driven

Connectors own webhook verifier strategies. Canonical webhook contracts must support raw-body preservation, provider-specific signatures/HMAC/timestamps, endpoint challenges, bearer/basic/custom-header controls where documented, optional IP allowlisting, replay/duplicate detection, batch/order metadata and idempotent normalization.

The core must not assume that every provider exposes the same signature mechanism.

### 7. AI architecture remains governed and vendor-neutral

Keep the existing specialized-agent model and deterministic execution boundary. Research/market-intelligence behavior belongs behind the AI gateway and typed tools rather than an unrestricted super-agent.

Research-capable AI must preserve source provenance, access date, confidence/conflict information and implementation impact.

OpenAI, Anthropic, Google, cloud-hosted models, private/local models or later providers are replaceable routes selected by capability, privacy/region policy, measured quality, latency, cost and compatible fallback—not brand preference.

### 8. Quality engineering baseline is accepted

`.ai/12-QUALITY-ENGINEERING-GATES.md` is the cross-cutting planning baseline.

Current external baselines include:

- NIST SSDF 1.1 as the current final SSDF baseline; SSDF 1.2 remains an initial public draft and is monitored rather than silently treated as final policy;
- OWASP ASVS for applicable application verification;
- OWASP AISVS 1.0 for AI-enabled system security verification;
- SLSA build/provenance guidance with a practical measured target;
- GitHub Actions immutable full-SHA pinning guidance;
- WCAG 2.2 Level AA target for first-party UI;
- SRE-style measurable SLO/error-budget/capacity practices.

These versions are not frozen forever. Active Research Gates must revalidate standards that can change.

### 9. Security-tool coverage must be truthful

TASK-0014 must not claim that one scanner covers the whole stack. Current GitHub CodeQL support does not provide PHP analysis. PHASE-03 security hardening must therefore distinguish supported CodeQL surfaces such as JavaScript/TypeScript/GitHub Actions from a PHP-capable SAST/taint strategy, and it must document actual coverage and exceptions.

### 10. Diverse PHASE-03 reference connectors

TASK-0013 research selects the following reference connector families for TASK-0017, subject to fresh revalidation when TASK-0017 activates:

- Amazon SES — delivery reference;
- Brevo — delivery/marketing-platform reference;
- Gmail API — mailbox reference.

They intentionally exercise different auth, quota, readiness and provider semantics. Concrete social connectors remain later-phase work; social API research in TASK-0013 exists to ensure the PHASE-03 abstraction does not become email-only.

## Extension-only planning rule

Research may:

- add a prerequisite task;
- split an unstarted task while preserving traceability;
- add acceptance criteria;
- introduce a proposed ADR;
- add a connector capability or test matrix;
- defer a capability with explicit evidence and approval.

Research may not silently:

- delete a planned requirement;
- weaken an acceptance criterion;
- mark work complete;
- reorder completed history;
- bypass an earlier dependency;
- change permanent security/consent/provider-neutral invariants;
- promote a provider/model from marketing claims without measured evidence.

## Audit findings accepted as roadmap obligations

The roadmap must retain explicit work for:

- repository rules/required status enforcement evidence;
- dependency, SAST/code, secret and container scanning;
- full-SHA pinned third-party GitHub Actions or reviewed time-bounded exceptions;
- SBOM/provenance/release integrity;
- PostgreSQL-backed integration and critical browser paths;
- load/capacity/queue-saturation and p95/p99 SLO gates before scale claims;
- accessibility, keyboard, responsive and visual-regression gates for critical UI;
- backup/restore, RPO/RTO, disaster recovery and webhook/event replay evidence;
- security regression plus property/fuzz/concurrency/fault testing where justified;
- AI prompt-injection, tool-abuse, cross-tenant context, exfiltration, budget-denial, malformed-output, unsafe-fallback and AISVS-derived evaluations;
- immutable auditability and deterministic policy for privileged AI/provider actions;
- design-system consistency and loading/empty/error/permission/partial-success states;
- data retention, deletion/export, classification, redaction, residency and privacy research before relevant releases.

## Consequences

### Positive

- Future AI sessions have a complete long-horizon direction instead of inventing work.
- Fast-changing external constraints are revalidated immediately before implementation.
- Social, creative, community, email, messaging, analytics and AI share canonical policy/data foundations.
- Provider/model churn does not force core rewrites.
- Capability/readiness modeling fails closed instead of overclaiming provider support.
- Audit findings become measurable roadmap obligations.

### Trade-offs

- More planning and pre-implementation research are required.
- Some tasks may stop after research when external/legal/security evidence invalidates assumptions.
- Provider manifests become richer than simple feature flags.
- The roadmap can grow beyond TASK-0100 when justified; such growth must remain explicit.

## Acceptance evidence

ADR-0002 is accepted under TASK-0013 because:

1. `.ai/research/PHASE-03/TASK-0013-RESEARCH.md` records current provider, social, market and security evidence.
2. Existing registry responsibilities were reviewed: `Content`, `Campaigns`, `Delivery`, `Analytics` and `Notifications` do not own the accepted Assets/Publishing/Community lifecycles without creating mixed responsibilities.
3. The PHASE-03 task chain remains weighted to 100 and preserves phase sequencing.
4. Concrete provider/social implementation is still prohibited by TASK-0013.
5. The preplanned skeleton remains extension-only rather than an authority to work ahead.

Acceptance of this ADR authorizes canonical vocabulary and governance changes only. Feature implementation remains controlled by each later active task.
