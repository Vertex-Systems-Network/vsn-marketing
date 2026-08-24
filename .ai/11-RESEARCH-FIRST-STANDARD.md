# Research-First Development Standard

This standard applies to development AI and humans working on VSN Marketing. It exists because the product depends on fast-changing provider APIs, platform policies, security guidance, legal/compliance constraints, AI capabilities, and market workflows.

## Governing rule

**Do not implement a new system from assumptions alone. Research the current external reality first, record evidence, then extend or confirm the preplanned roadmap before code begins.**

Research can extend the plan. Research cannot silently replace the plan, erase requirements, weaken acceptance criteria, or bypass dependencies.

## When the Research Gate is mandatory

A dated research evidence pack is required before implementation of any:

- new product subsystem or canonical domain capability;
- new provider, social network, mailbox, marketing platform, payment/billing system, storage/search service, or external API;
- new AI model/provider, agent capability, tool class, memory/RAG mechanism, or autonomous loop;
- new security-sensitive authentication, authorization, credential, webhook, signing, encryption, or compliance mechanism;
- significant infrastructure/runtime/framework choice or major-version migration;
- user-facing workflow where mature market products establish important usability expectations;
- regulated/compliance-sensitive feature;
- deliverability, sender-identity, suppression, privacy, retention, residency, analytics/attribution, or tracking feature;
- major performance/scaling mechanism whose design depends on current runtime/provider limits.

Routine bug fixes, typo-only changes, and refactors that do not change external behavior do not require a new research pack unless uncertainty about current behavior materially affects correctness.

## Source priority

Research should use multiple source classes when the task warrants them. Prefer sources in this order:

1. official API/provider/platform documentation and current release notes;
2. standards bodies and primary technical specifications;
3. official security/privacy/compliance guidance;
4. upstream framework/library documentation and changelogs;
5. provider status/limits/policy pages and official SDK repositories;
6. reputable engineering/reference implementations and incident/postmortem material;
7. current competitor/market product documentation for workflow benchmarking;
8. community discussions only as secondary evidence for undocumented behavior or operational experience.

Never use a blog summary to override an official API contract when the official contract is available.

## Minimum research questions

Every research pack must answer the questions relevant to the task:

### Product and market

- What do leading current systems provide for this workflow?
- Which behaviors are table stakes versus genuine differentiators?
- Which UX conventions reduce operator mistakes?
- What important capability is missing from the current VSN preplan?

### API/provider

- What current API/version is supported?
- Which auth/scopes/account types/app-review requirements apply?
- What capabilities are supported, unsupported, restricted, or asynchronous?
- What quotas/rate limits/media limits/payload limits/timeouts exist?
- What webhook/event guarantees and signature/replay mechanisms exist?
- What sandbox/test environment exists?
- What deprecations or migration deadlines exist?
- What terms/anti-abuse rules materially constrain implementation?

### Architecture and data

- Which facts belong in canonical VSN models versus provider references?
- Which operations require idempotency, reconciliation, snapshots, retries, locks, or ordering?
- What data must be retained, redacted, encrypted, deleted, exported, or region-scoped?
- What failure modes require fail-closed behavior?

### Security and privacy

- What is the current threat model?
- What are the current authoritative security recommendations?
- What supply-chain/dependency risks are introduced?
- Does the feature expand PII, secrets, tracking, consent, or authorization scope?
- What abuse cases could an AI agent or malicious user exploit?

### Performance and reliability

- What latency/throughput/queue-volume expectations exist?
- What provider/runtime constraints determine capacity?
- What SLOs and recovery objectives should be measured?
- What happens during partial provider failure, duplicate events, network partitions, or delayed webhooks?

### AI-specific

- Which capabilities are actually required: structured output, tools, vision, long context, reasoning, batch, embeddings, regional processing, etc.?
- What schema and deterministic policy boundary controls execution?
- What prompt-injection, data-exfiltration, cross-tenant, hallucination, unsafe-tool, budget, and fallback risks exist?
- How will competing model routes be evaluated instead of selected by brand preference?

## Evidence pack format

Store research under:

```text
.ai/research/<PHASE-ID>/<TASK-ID>-RESEARCH.md
```

The pack must contain at least:

```text
# <TASK-ID> Research Pack

- researched_at: ISO-8601 timestamp
- task: TASK-XXXX
- phase: PHASE-XX
- scope: ...
- researcher: human/AI identity when available

## Sources
| Source | Type | Version/date | Accessed | Why authoritative |

## Current external reality
...

## Market/reference workflow
...

## Security/privacy findings
...

## API/platform constraints
...

## Performance/reliability findings
...

## Conflicts with current assumptions
...

## Required roadmap extensions
...

## Rejected options
...

## Decision impact
- confirm existing plan / extend plan / propose ADR / block task

## Freshness risks
...
```

If a task has no meaningful external dependency, the research pack can be short but must explicitly explain why extensive internet research was not necessary.

## Freshness rule

The research must be current at the moment the task is activated. For fast-changing APIs, AI models, pricing/quotas, platform policies, laws/regulations, security advisories, and deprecation schedules, verify the latest official documentation during the active session rather than relying on repository notes or prior chat history.

A previous research pack is reusable only when its material claims are revalidated. Record the revalidation date and changed sources.

## Research-to-plan reconciliation

After research and before implementation, classify every material finding as one of:

- `CONFIRMS_PLAN`
- `NEW_PREREQUISITE`
- `NEW_ACCEPTANCE_CRITERION`
- `NEW_TASK`
- `ADR_REQUIRED`
- `BLOCKER`
- `DEFER_WITH_APPROVAL`
- `NO_PRODUCT_IMPACT`

If research changes scope, update the plan first. The agent must never implement the newly discovered work and retroactively edit the plan afterward.

## Extension-only continuity rule

The preplanned roadmap is a minimum known plan. Research is allowed to append justified work or split unstarted work with traceability. Existing completed task IDs remain immutable. Dependencies and acceptance criteria cannot be weakened merely because external reality makes them difficult.

When an assumption is invalid, record the conflict and reconcile explicitly.

## Research quality checks

A research pack is unacceptable when it:

- cites only one vendor marketing page for a material architectural decision;
- omits official docs that are available;
- uses stale version information without revalidation;
- lists links without extracting implementation impact;
- hides contradictory sources;
- recommends a provider/model solely from popularity;
- converts uncertain behavior into a guessed contract;
- treats market parity as proof that VSN should copy proprietary behavior;
- includes secrets, customer-sensitive data, or restricted material.

## Development-AI session rule

Before starting a new system task, the agent must:

1. validate canonical repository state;
2. inspect the preplanned roadmap and active acceptance criteria;
3. perform the Research Gate;
4. write/revalidate the research pack;
5. reconcile findings into roadmap/task/ADR changes;
6. validate continuity again;
7. only then implement the active task.

This sequence is mandatory even when the implementation appears easy.