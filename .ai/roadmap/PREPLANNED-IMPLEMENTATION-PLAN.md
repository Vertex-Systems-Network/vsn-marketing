# Preplanned Implementation Plan — TASK-0013 through TASK-0100

This document is the long-horizon minimum known implementation plan for VSN Marketing after PHASE-02.

It does **not** authorize future implementation by itself. `CURRENT-STATE.yaml`, registered machine task files, dependencies, accepted ADRs, and phase activation still control execution.

## Planning laws

1. TASK-0013 through TASK-0100 are reserved as the current preplanned skeleton.
2. Every new subsystem/provider/channel/AI capability must pass `.ai/11-RESEARCH-FIRST-STANDARD.md` before implementation.
3. Research confirms or extends this plan before code begins.
4. Research may append prerequisites, split unstarted work with traceability, add acceptance criteria, or require an ADR.
5. Research may not silently delete requirements, weaken acceptance, skip dependencies, renumber completed history, or pull later-phase implementation forward.
6. Every phase ends with explicit certification against applicable `.ai/12-QUALITY-ENGINEERING-GATES.md` controls.
7. Provider and AI vendors remain replaceable implementations behind canonical contracts.
8. The final task number is not a project limit. If research justifies additional work, continue with TASK-0101+ rather than compressing or hiding scope.

## Cross-cutting requirements inherited by every phase

- Organization → Workspace → Brand isolation.
- Deterministic RBAC, consent, suppression, approvals, quotas, budgets, and compliance gates.
- Immutable/auditable state-changing actions.
- Idempotent/retry-safe external operations.
- Provider-neutral canonical models.
- Research evidence and source provenance.
- Security/supply-chain/privacy gates.
- Production-representative integration coverage.
- SLO/performance evidence before scale claims.
- WCAG 2.2 AA target for first-party UI.
- AI structured output, typed tools, policy evaluation, and external eval promotion gates.

---

# PHASE-03 — Provider Framework, Security Baseline, and Initial Adapters

Purpose: establish the provider/channel abstraction without becoming email-only, while closing the audit security/supply-chain prerequisites before production-capable external integrations.

- **TASK-0013 — Research and reconcile PHASE-03 provider/channel architecture.** Research current provider, marketing-platform, mailbox, social publishing, messaging, webhook, credential, and API-policy patterns; disposition ADR-0002; finalize PHASE-03 without provider implementation.
- **TASK-0014 — Harden repository and software-supply-chain governance.** Required status/ruleset evidence, SAST, dependency/secret/container scanning, CI action hash pinning, SBOM/provenance plan, OpenSSF posture, and documented exceptions.
- **TASK-0015 — Implement canonical provider capability and connection foundation.** Provider, ProviderConnection, ProviderCapability, ProviderQuota, authentication metadata, secret references, tenant isolation, and capability discovery.
- **TASK-0016 — Implement adapter, error, quota, webhook, and reconciliation contracts.** Stable provider-neutral contracts, normalized errors/events, idempotency, retries, signature/replay rules, rate/quota interfaces, version/deprecation metadata.
- **TASK-0017 — Implement initial reference connectors and sandbox contract matrix.** Reference delivery/marketing/mailbox adapters selected by TASK-0013 research; no provider owns canonical data.
- **TASK-0018 — Certify PHASE-03.** Provider-neutrality, tenant/security gates, connector contract tests, supply-chain controls, full CI, and exact-head evidence.

# PHASE-04 — Delivery Engine

Purpose: make outbound execution reliable, idempotent, observable, throttled, and provider-neutral.

- **TASK-0019 — Research delivery-engine semantics and current provider constraints.** Queue/rate/retry/failover patterns, provider quotas, duplicate-risk, scheduling precision, and operational SLOs.
- **TASK-0020 — Implement canonical message, recipient materialization, and immutable execution snapshots.** Marketing/transactional separation and reproducible send inputs.
- **TASK-0021 — Implement queue routing, idempotency, rate limits, quotas, and backpressure.** Workspace/provider/channel controls with concurrency safety.
- **TASK-0022 — Implement retry classification, circuit breakers, dead letters, reconciliation, and failover.** Compatible fallback only; never policy bypass.
- **TASK-0023 — Establish delivery SLO, load, saturation, fault-injection, and PostgreSQL/Redis production-parity gates.** Measure p95/p99, queue age, throughput, duplicate behavior, and failure recovery.
- **TASK-0024 — Certify PHASE-04.** End-to-end delivery safety/reliability/performance evidence.

# PHASE-05 — Domains, Sender Identity, Suppression, and Deliverability

Purpose: make permission-based sending operationally safe and reputation-aware.

- **TASK-0025 — Research current deliverability, sender-authentication, provider, and jurisdictional requirements.** Revalidate standards/policies before implementation.
- **TASK-0026 — Implement sender domains and sender identities.** Verification lifecycle, secret/DNS evidence references, tenant boundaries, and provider synchronization interfaces.
- **TASK-0027 — Implement suppression, unsubscribe/preferences, bounce, and complaint processing.** Deterministic pre-routing gates and evidence-preserving state.
- **TASK-0028 — Implement frequency caps, reputation/health signals, and safe sending policy.** No anti-abuse evasion or fake-account rotation.
- **TASK-0029 — Implement deliverability observability and bounded remediation recommendations.** Human/policy-gated actions for risky changes.
- **TASK-0030 — Certify PHASE-05.** Suppression bypass = zero in tests; domain/sender/deliverability policy matrix green.

# PHASE-06 — Canonical Content, Template, Creative, and Asset Studio

Purpose: own reusable channel content and creative assets independently of providers.

- **TASK-0031 — Research current content editors, template formats, asset workflows, rendering security, and channel media requirements.** Benchmark mature market workflows and accessibility expectations.
- **TASK-0032 — Implement canonical content/template/version/component model.** Immutable versions, variables, localization, provenance, and channel rendering contracts.
- **TASK-0033 — Implement canonical asset library and variant pipeline.** Use the ADR-0002-accepted `Assets` boundary for media metadata, rights/provenance, transformations, channel variants and object-storage isolation.
- **TASK-0034 — Implement safe editor/render/compiler pipeline.** Drag/drop/code pathways as appropriate, sanitization, preview, test rendering, async compilation, and untrusted-content controls.
- **TASK-0035 — Implement brand knowledge/kit, reusable components, approvals, and provider template synchronization.** Canonical VSN source remains authoritative.
- **TASK-0036 — Certify PHASE-06.** Rendering security, asset isolation, accessibility, visual regression, performance, version reproducibility, and provider sync tests.

# PHASE-07 — Campaigns, Publishing, Approvals, and Scheduling

Purpose: orchestrate governed cross-channel publishing and campaigns from one canonical calendar.

- **TASK-0037 — Research current campaign/social publishing APIs, app-review/scopes, scheduling constraints, media rules, and market calendar workflows.** Capture platform-specific restrictions before implementation.
- **TASK-0038 — Implement campaign lifecycle, snapshots, recipients/targets, approvals, and audit history.** Draft/review/approved/scheduled/running/cancelled/completed states.
- **TASK-0039 — Implement unified editorial/campaign calendar and timezone-safe scheduler.** DST, reschedule, cancellation, missed-run, and approval timing behavior.
- **TASK-0040 — Implement channel-neutral publication lifecycle.** Use the ADR-0002-accepted `Publishing` boundary for target accounts, publication attempts, provider status reconciliation, supported edit/delete, retries, idempotency and media references.
- **TASK-0041 — Implement campaign/publishing operator UX.** Bulk safeguards, previews, channel adaptations, approval queues, permission/error/partial-success states.
- **TASK-0042 — Certify PHASE-07.** Scheduling precision, duplicate prevention, approval enforcement, social/provider contract matrices, PostgreSQL-backed browser flows, accessibility and performance.

# PHASE-08 — Segmentation and Natural-Language Segment Compiler

Purpose: produce deterministic audiences from canonical customer/event data, with AI only compiling into validated structures.

- **TASK-0043 — Research segmentation/privacy/query patterns and benchmark audience builders.** Include scale/cardinality and PII risks.
- **TASK-0044 — Implement canonical segment definition AST and deterministic query compiler.** Tenant-safe, versioned, explainable filters.
- **TASK-0045 — Implement natural-language-to-segment structured compiler.** AI proposes AST only; deterministic validator owns executable semantics.
- **TASK-0046 — Implement preview/count/freshness/cost guards and audience UX.** Explain exclusions, permissions, invalid rules, and estimation.
- **TASK-0047 — Certify PHASE-08.** Cross-tenant, injection, deterministic equivalence, scale, AI hallucination, and query-performance tests.

# PHASE-09 — Journey and Automation Engine

Purpose: orchestrate deterministic event-driven lifecycle automation across channels.

- **TASK-0048 — Research current workflow/journey engines, concurrency models, wait semantics, and market builders.** Identify replay/versioning/failure patterns.
- **TASK-0049 — Implement versioned journey graph, node registry, validation, enrollment, and immutable versions.** Only registered node types execute.
- **TASK-0050 — Implement triggers, waits, conditions, branches, actions, goals, exits, and re-entry policy.** Timezone and event ordering defined.
- **TASK-0051 — Implement concurrency safety, idempotent node execution, retries, cancellation, replay, and recovery.** Durable execution state.
- **TASK-0052 — Implement journey builder, simulator, validation UX, and execution timeline.** Preview before activation and expose blocked/failed states.
- **TASK-0053 — Certify PHASE-09.** Long-running/replay/fault/concurrency/provider/consent/suppression and browser acceptance evidence.

# PHASE-10 — AI Gateway and Specialized Marketing Agents

Purpose: provide vendor-neutral, observable, policy-controlled product AI with rigorous evaluations.

- **TASK-0054 — Research current AI providers/models, structured output/tooling, privacy/region terms, pricing, context, media capabilities, and safety guidance.** Produce measured route candidates, not popularity rankings.
- **TASK-0055 — Implement AI gateway, capability routing, fallback, budgets, circuit breaking, usage/cost telemetry, and model-route configuration.** No core workflow hardcodes a model vendor.
- **TASK-0056 — Implement context assembler, workspace/brand/customer/run memory boundaries, retrieval provenance, redaction, freshness, and context manifests.** Cross-tenant leakage forbidden.
- **TASK-0057 — Implement structured output validation, typed tool executor, deterministic policy/risk/approval gates, and auditable result envelopes.** Models propose; software authorizes.
- **TASK-0058 — Implement specialized agent runtime, prompt registry integration, evaluation harness, and candidate promotion flow.** Existing canonical agents first; any Research/Market Intelligence agent must be explicitly registered and evaluated under ADR-0002 and active-task governance.
- **TASK-0059 — Implement content/creative AI adapters.** Text, vision, image/video or other media capabilities only through evaluated provider-neutral routes, provenance, brand/policy review, and rights/safety checks.
- **TASK-0060 — Implement AI red-team and abuse-resistance suite.** Prompt injection, indirect injection, tool abuse, exfiltration, cross-tenant context, hallucinated IDs, policy manipulation, unsafe fallback, budget denial, infinite loops, and self-modification attempts.
- **TASK-0061 — Certify PHASE-10.** Golden evals, deterministic policies, model fallback, privacy/security, cost/latency, canary/rollback and exact-head evidence.

# PHASE-11 — Experimentation and Adaptive Optimization

Purpose: measure changes safely and allow bounded optimization without self-approving AI.

- **TASK-0062 — Research current experimentation/statistical practices and marketing experimentation UX.** Power, bias, multiple testing, novelty and stopping risks.
- **TASK-0063 — Implement experiment, variant, assignment, holdout/control, randomization, and exposure logging.** Deterministic reproducibility.
- **TASK-0064 — Implement A/B and multivariate campaign/content/timing/audience experiments.** Guard against contamination and missing exposure data.
- **TASK-0065 — Implement bounded AI optimizer recommendations and candidate promotion.** AI cannot self-promote based only on its judgment.
- **TASK-0066 — Implement statistical guardrails, confidence/effect reporting, multiple-comparison policy, and experiment QA.** Avoid false certainty.
- **TASK-0067 — Certify PHASE-11.** Assignment integrity, statistical correctness, rollback and AI-optimization policy evidence.

# PHASE-12 — Analytics, Funnels, Attribution, Revenue, and LTV

Purpose: turn canonical events and channel outcomes into trustworthy decision data.

- **TASK-0068 — Research current analytics/attribution/privacy patterns, metric definitions, and scale options.** Do not add ClickHouse/OpenSearch without measured need and ADR.
- **TASK-0069 — Implement analytics event/metric model, lineage, aggregation, freshness, deduplication, and reconciliation foundation.** Facts remain versioned and explainable.
- **TASK-0070 — Implement funnels, cohorts, lifecycle, content/channel performance, and retention analytics.** Late/out-of-order events defined.
- **TASK-0071 — Implement attribution, revenue, LTV, and incrementality-ready models.** Attribution model/version disclosed.
- **TASK-0072 — Implement dashboards, anomaly detection, scheduled reports, and AI analytics explanations.** Separate measured fact from inference.
- **TASK-0073 — Implement data-quality monitors and provider/source reconciliation.** Drift, freshness, missing/duplicate events, and metric mismatches observable.
- **TASK-0074 — Certify PHASE-12.** Metric correctness, privacy, reconciliation, scale/SLO, UI accessibility, and AI explanation evals.

# PHASE-13 — Omnichannel, Social Operations, Community, and Listening

Purpose: expand from email/provider foundation to full permission-based communication and social operations.

- **TASK-0075 — Research current SMS, WhatsApp, RCS, push, in-app, Instagram, Facebook, LinkedIn, TikTok, X, Pinterest, YouTube, Threads and other prioritized channel APIs/policies.** Only channels justified by current official support enter implementation.
- **TASK-0076 — Implement messaging adapters and canonical channel capability mapping.** SMS/WhatsApp/push/RCS/in-app prioritized by research and business value.
- **TASK-0077 — Implement prioritized social publishing adapters.** Account types, app review, scopes, media, scheduling/direct publish, status polling, edit/delete and analytics per connector.
- **TASK-0078 — Implement normalized community inbox.** Use the ADR-0002-accepted `Community` boundary for comments, mentions, conversations/DMs where APIs permit, assignments, moderation and AI response proposals.
- **TASK-0079 — Implement listening and market/competitor/topic signals where permitted.** Respect platform policies; no unauthorized scraping.
- **TASK-0080 — Implement cross-channel normalized engagement and publication analytics.** Preserve provider-specific provenance/limitations.
- **TASK-0081 — Certify PHASE-13.** Channel contract, policy, privacy, rate-limit, moderation, social UI, community isolation and failure-reconciliation evidence.

# PHASE-14 — AI Connector Factory

Purpose: accelerate new provider integration without allowing generated code to bypass engineering gates.

- **TASK-0082 — Research current API-description/code-generation/sandbox/security-review practices.** Threat-model untrusted documentation and generated code.
- **TASK-0083 — Implement documentation/OpenAPI ingestion, source provenance, capability extraction, and connector plan generation.** No execution from untrusted docs.
- **TASK-0084 — Implement generated adapter/test candidate pipeline.** Generated code remains candidate-only.
- **TASK-0085 — Implement static checks, contract tests, sandbox tests, security review, provenance, and canary activation pipeline.** Human/deterministic approval before active.
- **TASK-0086 — Implement compatibility scoring, deprecation monitoring, rollback/disable, and connector lifecycle observability.** Provider changes do not silently break core.
- **TASK-0087 — Certify PHASE-14.** Malicious-doc, insecure-codegen, contract, sandbox, approval and rollback adversarial tests.

# PHASE-15 — Bounded Autonomous Marketing Loops

Purpose: allow AI to operate within explicit goals, risk tiers, budgets, approvals, and measurable rollback-safe limits.

- **TASK-0088 — Research current agent/autonomy safety, marketing optimization, regulatory, and incident patterns.** Revalidate boundaries before autonomy.
- **TASK-0089 — Implement bounded goal → plan → propose → execute → observe → evaluate loops.** Only registered tools and explicit workspace policy.
- **TASK-0090 — Implement autonomy budgets, rate/volume limits, risk tiers, approval checkpoints, kill switch, and global/workspace emergency stop.** Agents cannot redefine limits.
- **TASK-0091 — Implement safe optimization canaries, rollback, holdouts, and externally computed promotion thresholds.** No self-approval.
- **TASK-0092 — Implement autonomy red-team suite and abuse monitoring.** Prompt injection, runaway spend/send, policy gaming, collusion between agents, exfiltration, destructive loops, and metric gaming.
- **TASK-0093 — Certify PHASE-15.** Prove bounded autonomy fails closed under adversarial/failure conditions.

# PHASE-16 — Enterprise, Governance, Residency, White Label, and Resilience

Purpose: make the platform deployable for enterprise, agencies, regulated customers, and recoverable production operations.

- **TASK-0094 — Research current enterprise identity, privacy, residency, audit, retention, accessibility, procurement, and compliance expectations.** Region/industry claims require current evidence.
- **TASK-0095 — Implement enterprise identity/governance.** SSO, SCIM, advanced RBAC/policy, session/admin controls, organization governance and audit exports.
- **TASK-0096 — Implement data residency, retention, export/deletion, legal-hold-compatible boundaries, and regional AI/provider routing policy.** Claims scoped to supported regions.
- **TASK-0097 — Implement white-label and agency/client hierarchy capabilities.** Tenant safety, delegated administration, brand customization and permission inheritance without data leakage.
- **TASK-0098 — Implement billing, entitlements, quotas, metering, plans, and cost controls.** Deterministic billing authority; AI cannot commit spend without policy.
- **TASK-0099 — Implement backup/restore, RPO/RTO, disaster recovery, business continuity, release rollback, and recovery exercises.** Restore-tested evidence mandatory.
- **TASK-0100 — Certify enterprise production readiness.** Security/privacy/supply-chain/accessibility/performance/reliability/DR/AI-governance/tenant-isolation and exact-head acceptance matrix.

---

# Explicit audit-to-plan mapping

| Audit finding | Planned treatment |
|---|---|
| No registered successor after TASK-0012 | TASK-0013 + PHASE-03 registration |
| Canonical blocker/state inconsistency | TASK-0013 reconciliation before implementation |
| Social publishing/community/assets under-specified | ADR-0002; TASK-0033, TASK-0040, TASK-0077–0080 |
| Required security scanning not evidenced | TASK-0014 |
| CI actions referenced by mutable tags | TASK-0014 hash-pinning gate |
| Branch/status enforcement weak/not evidenced | TASK-0014 repository-rules acceptance |
| Critical browser E2E uses SQLite | TASK-0023 onward production-representative PostgreSQL browser paths |
| No formal load/SLO gates | TASK-0023 and inherited Quality Engineering Gates |
| No accessibility/visual regression baseline | TASK-0036 onward, WCAG 2.2 AA |
| PHASE-01 phase-document traceability gap | TASK-0013 + `.ai/roadmap/PHASE-01-RETROSPECTIVE.md`, explicitly without fabricating an original phase plan |
| Product UI barely started | PHASE-06/07 onward coherent design-system/operator UX gates |
| AI prompt/tool/provider abuse risks | TASK-0060, TASK-0092 and inherited AI gates |
| No DR/restore evidence | TASK-0099, with earlier task-level recovery tests where relevant |
| Fast-changing provider/model/API assumptions | Research-First Standard on every applicable task |

# Product capability coverage target

By the end of the preplanned roadmap, VSN Marketing is intended to provide a coherent foundation for:

- Customer 360/CDP-style identity, consent, events and lifecycle data;
- lists, tags and dynamic segments;
- email marketing and transactional messaging;
- SMS, WhatsApp, RCS, push and in-app channels where provider research supports them;
- social publishing across prioritized supported networks;
- unified editorial/campaign calendar;
- creative/template/asset studio;
- campaign approvals and immutable snapshots;
- journeys/automations;
- community inbox/listening where APIs and permissions allow;
- experiments and adaptive optimization;
- funnels, cohorts, attribution, revenue and LTV analytics;
- specialized AI strategy/content/audience/journey/deliverability/analytics/compliance/QA/connector capabilities;
- provider/model portability;
- AI connector generation with sandbox/security/human gates;
- bounded autonomous marketing loops;
- enterprise governance, white label, residency, billing and disaster recovery.

This is a minimum planned product direction. Current research may extend it before each subsystem is implemented.
