# TASK-0088 Research Pack - Bounded Autonomous Marketing Loops

- researched_at: 2026-10-09
- task: TASK-0088
- phase: PHASE-15
- scope: goal-to-action marketing agents, deterministic execution authority, campaign consent, safety budgets, canaries, reversibility and incident response
- researcher: VSN Marketing Supervisor
- status: RESEARCHED / ACCEPTANCE PENDING EXACT-HEAD CI

## Official and primary sources

| Source | Version / reviewed | Current finding and implementation consequence |
| --- | --- | --- |
| NIST AI Risk Management Framework, https://www.nist.gov/itl/ai-risk-management-framework | AI RMF 1.0; reviewed 2026-10-09 | Govern, Map, Measure, Manage remain the independent risk-management frame. Autonomous proposals must have explicit intended outcomes, measurable residual risk, monitoring and human accountability. NIST reports the base framework is being revised; do not assert a future normative version. |
| NIST Generative AI Profile, https://www.nist.gov/publications/artificial-intelligence-risk-management-framework-generative-artificial-intelligence | NIST AI 600-1 (2024), page updated 2026; reviewed 2026-10-09 | Evaluate confabulation, data/privacy risks, harmful outputs, information integrity and model/tool risks; treat LLM generations as untrusted proposals rather than policy decisions. |
| OWASP Top 10 for Agentic Applications, https://genai.owasp.org/2025/12/09/owasp-top-10-for-agentic-applications-the-benchmark-for-agentic-security-in-the-age-of-autonomous-ai/ | 2025-12-09 publication; reviewed 2026-10-09 | Agent goal hijacking, tool misuse, identity/privilege abuse, supply-chain manipulation, unexpected code execution and cascading failures require scoped typed tools and out-of-band execution gates. |
| OWASP agentic threats and mitigations, https://genai.owasp.org/resource/agentic-ai-threats-and-mitigations/ | 2025-02; reviewed 2026-10-09 | Explicitly model untrusted inputs, context poisoning, inter-agent escalation, unsafe delegation, exfiltration and permission amplification. |
| OWASP Q3-2026 exploit roundup, https://genai.owasp.org/2026/10/08/genai-and-agentic-ai-exploit-roundup-q3-2026/ | 2026-10-08; reviewed 2026-10-09 | Recent *analyst-described* exploit patterns involve connected-tool authority, shared-agent paths and prompt injection. Treat incident narratives as threat hypotheses, not proof every scenario applies to VSN. |
| UK ICO Direct marketing guidance, https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/direct-marketing-guidance/ | Updated 2026-04-28; reviewed 2026-10-09 | Privacy-by-design, lawful basis, transparent data use, and honoring opt-outs must precede automated marketing actions. Rules vary by jurisdiction/channel. |
| UK ICO profiling / lead collection, https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/direct-marketing-guidance/collect-information-and-generate-leads/ | Reviewed 2026-10-09 | Marketing profiling can cause discrimination and has special-category/automated-decision risks; never infer individualized legal eligibility from LLM text. |
| FTC Online advertising and marketing, https://www.ftc.gov/business-guidance/advertising-marketing/online-advertising-marketing | Reviewed 2026-10-09 | Marketing claims, endorsements, disclosures and review representations require truthful, independently validated evidence. AI-generated copy is not intrinsically substantiated. |
| EU AI Act enforcement FAQ, https://ai-act-service-desk.ec.europa.eu/en/ai-act/faq/when-does-enforcement-start | Reviewed 2026-10-09 | Certain transparency/prohibited-use and GPAI enforcement powers began 2026-08-02 with staggered exceptions. Do not assume every marketing agent is a high-risk system or every rule has the same effective date; require deployment-specific legal mapping. |
| GitHub Actions secure use, https://docs.github.com/en/actions/reference/security/secure-use | Reviewed 2026-10-09 | Untrusted model/tool output must not reach privileged CI, secrets or deployment paths by default; require minimum scoped tokens and separate protected release actions. |

## Current product and reference workflow

Canonical VSN PHASE-02 consent, PHASE-04 delivery, PHASE-05 suppression, PHASE-07 campaign approvals, PHASE-09 journeys, PHASE-10 typed AI tools and PHASE-11 offline optimization are required dependencies, not permissions for live autonomous sending. PHASE-12/13 engagement facts can be delayed, noisy, spoofed or incomplete. Previously planned email open/click features (EM-01..06) remain unactivated and cannot be substituted with claimed human-reading evidence.

The expected operator workflow is: declare a workspace goal and authorized resource envelope -> AI produces a versioned bounded proposal -> deterministic policy validates tenant, purpose, consent, suppression, schedule, destination, content and budget -> owner/admin reviews high-impact changes -> queue and adapter enforce idempotent action -> measure reliable facts -> external quality gate decides hold/rollback/promotion -> preserve immutable audit and emergency stop. Read-only preview, explainability, denied reasons, dry-run and rollback are required UX features, not optional debugging tools.

## Adversarial and privacy threat model

1. Goal hijack: malicious inbound email, page, analytics label, external document or connector description redefines objectives; isolate retrieved content as data.
2. Runaway sending/spend: recursive optimization, queue retries, worker concurrency or delegated agents exceed message volume, token cost, provider quotas, time limits or channel policies.
3. Self-approval / collusion: models forge consent, human approvals, benchmark evidence, identity claims, risk scores or results; all authority is signed/typed, independently evaluated and non-editable by agents.
4. Cross-tenant and exfiltration: a suggestion resolves to foreign tenant IDs, raw secrets/PII, private URLs or arbitrary webhook destinations; enforce tenant scoping and explicit destination/tool allowlists server-side.
5. Metric gaming and feedback loops: bot/proxy engagement, incomplete conversions, delayed webhook facts, holdout contamination and optional event omission cause false uplift; frozen eligibility, external denominators and confidence checks.
6. Prompt and memory poisoning: persistent campaign briefs, user-uploaded sources and retrieved research can introduce tool instructions; mark provenance, isolate memory by workspace and reject untrusted role changes.
7. Unsafe content: unsupported effectiveness/product claims, prohibited audience attributes, undisclosed endorsements and manipulative prompts; require source-backed content moderation and human approval where risk demands it.
8. Rollback failure: irreversible provider sends, already-processed jobs and stale approvals cannot be rolled back; stop *future* actions and provide corrective actions rather than claiming total reversibility.

## Deterministic system requirements

- Introduce a typed `AutonomyRun` proposal snapshot bound to tenant, actor, goal, approved tool registry, policy version, consent purpose, channel, geography and absolute time/volume/spend ceilings. Proposals never carry usable provider credentials.
- A non-LLM gate must check limits before each queue admission *and* immediately before external side effects; use transactionally reserved counters, durable idempotency keys, monotonic attempt budgets and replay-safe evidence. Model suggestions cannot raise ceilings.
- Split draft/preview from execution. Default OFF for external sending, publishing, changing provider settings, purchasing advertising, provisioning resources or changing billing. No authorization is inferred from the research or roadmap activation.
- Runtime kill switches (global and workspace) preempt all new admissions and worker claims. Existing irreversible send attempts require explicit reconciliation and provider-status checks.
- Typed approval records bind the exact content hash, plan version, audience, destination, time window, budget, approver and expiration; any material plan mutation invalidates approval.
- Optimization/canary evaluation operates on frozen cohorts, independently computed metrics, minimum sample/support requirements, time/latency quality, consent eligibility and rollback thresholds. Never allow AI to grade its own evidence.
- Store operator-readable decisions, denied reason codes, elapsed attempts, reserve/consume/refund journal, actor and tool/version provenance, redacted output and incident audit. No raw recipient PII or secrets in prompts or analytics diagnostics.
- Provide negative-case unit/property tests for quota race, repeated job, partial commit, cross-tenant attempt, prompt injection, approval replay, kill-switch concurrency, suppression race, falsified metrics, outbound URL abuse and deferred provider callbacks.
- Collect SLO measurements only from repeatable tests and traceable production-like infrastructure. Do not fabricate throughput, p95 queue time, uptime or win-rate.
- Require accessible preview/approval UX (WCAG 2.2 AA target) with clear disabled, pending, revoked, stopped, failed and unknown states.

## Research-to-plan decision

Classification: CONFIRMS_PLAN and NEW_ACCEPTANCE_CRITERION, with no architecture rebaseline. TASK-0088 through TASK-0093 remain the canonical ordered phase skeleton. Material new acceptance emphasis: independent authority enforcement on every side effect, race-safe budget reservation, immutable approval binding, global/workspace emergency stop, spoofed/late engagement quality, and realistic non-rollback of already irreversible provider actions.

Research does not grant permissions to send real marketing emails, launch ads, publish content, execute payments, rotate secrets, deploy providers or bypass review. PHASE-15 can implement and certify offline/fixture behavior while live execution stays disabled.

## Required follow-on and evidence matrix

- TASK-0088: current research and policy/abuse mapping; mark complete only after exact-head required tests and evidence.
- TASK-0089: typed goal/plan/propose/observe/evaluate state machine; default dry-run.
- TASK-0090: independent quota counters, risk-tier approvals, immutable policy and emergency stop.
- TASK-0091: holdout/canary, externally computed promotion and rollback/reconciliation.
- TASK-0092: injection, runaway-send/spend, tenant/exfiltration, metric fraud and incident simulation.
- TASK-0093: certify contracts, app/security/E2E/integration, adversarial negatives, recovery and exact-head/resulting-main gates.

## Freshness / unresolved claims

Provider send APIs, ad-account approvals, jurisdiction-specific permission and per-channel quotas are intentionally unverified for live execution; revalidate the selected provider and destination at the later connector/runtime activation gate. No external side effects are authorized by this pack.