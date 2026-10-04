# TASK-0043 Research Pack

- researched_at: 2026-09-26
- task: TASK-0043
- phase: PHASE-08
- scope: segmentation product patterns, canonical customer/event semantics, privacy, safe query compilation, audience evaluation/cost, and the PHASE-07/TASK-0042 plan drift
- researcher: AI Engineering Supervisor
- source baseline: protected `main` at `6d0269bfe9b44b0623fbe1eb0e4d59fd1462e115`

## Sources

| Source | Type | Version/date | Accessed | Why authoritative |
|---|---|---|---|---|
| [HubSpot: active and static segments](https://knowledge.hubspot.com/segments/what-is-the-difference-between-saved-filters-smart-lists-and-static-lists) | Product documentation | Updated 2026-08-10 | 2026-09-26 | Current first-party distinction among saved views and dynamic versus fixed-membership segments, plus use across workflows and reports. |
| [Salesforce Data 360: create a standard segment](https://help.salesforce.com/s/articleView?id=data.c360_a_create_a_segment.htm&language=en_US&type=5) | Product documentation | Current page | 2026-09-26 | Defines segment-on target objects, related attributes, publishing and evaluation scheduling. |
| [Customer.io: data-driven segments](https://docs.customer.io/messaging/segmentation/data-driven-segments/) | Product documentation | Current page | 2026-09-26 | Documents property/event conditions and nested condition groups for automatically changing membership. |
| [Customer.io: AI segment builder](https://docs.customer.io/ai/agent/ai-segment-builder/) | Product documentation | Current page | 2026-09-26 | Shows AI as a way to propose conditions while the operator remains responsible for the resulting audience. |
| [Klaviyo: segment conditions](https://help.klaviyo.com/hc/en-us/articles/115005062847) | Product documentation | Current page | 2026-09-26 | Documents typed property operators, event conditions and limits on which event properties are segmentable. |
| [Klaviyo: segment counts and estimates](https://help.klaviyo.com/hc/en-us/articles/51721301082011) | Product documentation | Current page | 2026-09-26 | Describes dynamic count freshness and consistent-time comparisons for audience counts. |
| [Adobe Experience Platform: segmentation methods](https://experienceleague.adobe.com/en/docs/experience-platform/segmentation/methods/overview) | Product documentation | Current page | 2026-09-26 | Distinguishes batch, streaming and edge evaluation and on-demand evaluation. |
| [Adobe Experience Platform: Audience Builder](https://experienceleague.adobe.com/en/docs/experience-platform/segmentation/ui/audience-builder) | Product documentation | Current page | 2026-09-26 | Describes definition validation, evaluation timestamps and audience construction UX. |
| [PostgreSQL 18: row security](https://www.postgresql.org/docs/18/ddl-rowsecurity.html) | Upstream database documentation | PostgreSQL 18 | 2026-09-26 | Primary specification for row-level security behavior and policy composition. |
| [PostgreSQL 18: EXPLAIN](https://www.postgresql.org/docs/18/using-explain.html) | Upstream database documentation | PostgreSQL 18 | 2026-09-26 | Primary guidance for inspecting scan and join plans; representative performance evidence must use the actual supported database version. |
| [PostgreSQL 18: multicolumn indexes](https://www.postgresql.org/docs/18/indexes-multicolumn.html) | Upstream database documentation | PostgreSQL 18 | 2026-09-26 | Primary guidance for index column order and query patterns. |
| [PostgreSQL 18: statement timeout](https://www.postgresql.org/docs/18/runtime-config-client.html#GUC-STATEMENT-TIMEOUT) | Upstream database documentation | PostgreSQL 18 | 2026-09-26 | Primary control for bounding statement execution time; application cancellation and transaction cleanup still need tests. |
| [OpenAI Structured Outputs](https://platform.openai.com/docs/guides/structured-outputs) | Model API documentation | Current guide | 2026-09-26 | First-party schema-constrained output capability; schema adherence does not establish semantic validity, tenant authority, or safe execution. |
| [OWASP SQL Injection Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html) | Security guidance | Current page | 2026-09-26 | Primary secure-coding guidance for parameterized values and allowlisted identifiers. |
| [EDPB: basic principles](https://www.edpb.europa.eu/topics/key-gdpr-concepts/basic-principles_en) | Regulator guidance | Current page | 2026-09-26 | Summarizes lawfulness, fairness, transparency, purpose limitation, data minimisation, accuracy, storage limitation and confidentiality. |
| [ICO: planning direct marketing](https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/direct-marketing-guidance/plan-direct-marketing/) | Regulator guidance | Current page | 2026-09-26 | Explains lawful-basis and profiling considerations, including special-category data used to target marketing. |
| [ICO: special-category data](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/special-category-data/what-is-special-category-data/) | Regulator guidance | Current page | 2026-09-26 | Clarifies that inferences about health, ethnicity, beliefs, politics and similar traits can themselves be sensitive personal data. |

## Current external reality

### Product and market patterns

- Current products generally separate a reusable definition from membership at a point in time. HubSpot distinguishes active/dynamic membership from static membership; Salesforce and Adobe expose evaluation/publishing schedules; Customer.io describes data-driven membership that changes as person/event data changes.
- Rule builders support typed attribute conditions, event conditions, related-record filters, group logic and explicit segment review. Some offer counts or estimates and expose when membership was evaluated. These are workflow references, not a reason to copy proprietary storage or runtime choices.
- Event filters are not equivalent across systems. Products differ in supported event-property depth, lookback windows, evaluation cadence and which event fields may be queried. VSN must define its own allowlist against the canonical event contract.
- AI segment builders are being offered in market, but vendor documentation presents the result as editable conditions for operator review. Structured output only solves syntax shape; VSN still needs independent schema, authorization, privacy and cost validation.

### Canonical domain and semantics

Repository evidence at the source baseline shows canonical contacts, contact identities, companies, lists/tags, immutable consent records, canonical customer events and a workspace-bound tenant context. The existing composite ownership constraints are useful foundations, but they do not by themselves guarantee that every compiled query joins through the right tenant keys.

Recommended PHASE-08 semantics:

- A saved segment is an immutable, versioned definition over an explicit base subject, initially a contact. A draft is editable; a published version is immutable. Updating creates a new version and never mutates earlier membership evidence.
- Attribute paths are stable registry IDs mapped by server code to approved columns/relations and typed operators. Unknown, hidden, sensitive, non-targetable or non-previewable fields fail closed.
- Boolean groups have explicit `all` (AND), `any` (OR) and single-child `not` semantics; nested groups preserve order-independent meaning after canonical normalization. Empty groups, ambiguous null semantics and implicit coercion are rejected.
- Null/missing is explicit (`is_set` / `is_not_set`); it is not silently converted to an empty string, false, zero or a negative match.
- Event predicates name a registered canonical event type and registered event-property ID, use a typed comparison, and pin a UTC evaluation instant. Relative windows are evaluated against that instant; absolute windows are half-open `[from,to)` UTC ranges. First/last/count/exists semantics must be explicit. Unsupported event property paths are rejected.
- Company, identity, list/tag and event relationships are traversed only through a reviewed join registry with workspace equality in every subquery/join. The browser/AI payload cannot select a tenant ID or relation path.
- Consent and suppression remain independent sending-eligibility authorities. They are not ordinary segment predicates and segment membership is never proof of permission to send. Final execution must re-evaluate current consent, suppression, authorization and policy.
- Segment evaluation records definition version/hash, evaluation timestamp, workspace-scoped identity, freshness, count semantics and materialization status. Stable snapshots may be used only as data outputs for the same workspace and version.

### Privacy and consent

- Segmentation is profiling/targeting work even when no message is sent. Apply purpose limitation, minimisation, transparency, retention and access controls to both inputs and outputs.
- Do not expose contact names, emails, identities, raw event payloads or property values in default preview responses. Show aggregate counts, freshness, rule explanations and bounded sample data only when a separate permission explicitly permits it.
- Sensitive attributes and inferred sensitive traits (including health, ethnicity, political/religious beliefs, sexual orientation and similar categories) are not targetable or AI-context fields by default. Any later exception requires an explicit legal/purpose assessment, policy decision and ADR with role, consent, audit and retention rules.
- A count can itself disclose information about a small group. The repository has no researched policy threshold; do not invent a universal numeric floor. TASK-0046 must use an authorization-aware disclosure policy and add a documented threshold only after product/privacy review.
- Suppression/unsubscribe, consent changes, deletion requests and exports can change eligibility after a saved segment was evaluated. Audience counts must label their evaluation time and freshness; execution rechecks eligibility, and data-subject operations continue to follow their canonical workflows.
- Keep AI inputs to field/event descriptions and the user's segment intent. Do not send customer rows, PII, raw event payloads, consent evidence or secrets to a model to produce a definition.

### Query security and tenant isolation

- Never execute model-generated SQL, query fragments, field names, function names, join paths or raw expressions.
- Use a closed AST schema, server-owned field/operator/event/join registry and deterministic compiler. Bind every literal value. SQL identifiers must come only from the static registry; reject unknown keys rather than attempting to sanitise them.
- Derive workspace scope from authenticated `TenantContext`, outside and independently from the AST. Every base query and join/subquery must include its own workspace key. Do not trust a client/model-supplied workspace, tenant, contact list, query scope or saved-segment ID.
- PostgreSQL RLS can provide defence in depth where supported, but role/pool configuration and bypass roles matter. Application scoping and composite relational constraints remain required. CI should exercise app isolation and database policy behavior separately.
- Start with a deliberately limited operator set: equality/inequality, numeric/date comparisons, explicit set/unset, bounded membership lists, and event exists/not-exists/count in bounded windows. Exclude free-form regex, arbitrary JSON paths, SQL functions, fuzzy search, cross-workspace comparisons, arbitrary joins and user-supplied sorting/grouping until separately reviewed.
- Set limits for AST depth, predicate count, values per `IN`, event window, preview rows, count work, execution time and cancellation. Enforce them before query construction. Use query timeout, bounded preview, pagination by stable keyset, and no unbounded export path.
- Use `EXPLAIN` against seeded, production-shaped PostgreSQL datasets, including skew/high-cardinality event types. Avoid treating an estimated plan or a small SQLite fixture as proof of production performance.

### Performance, freshness and evaluation

- Market products expose dynamic membership, batch schedules, evaluation timestamps and/or on-demand evaluation rather than claiming all segment counts are perpetually exact and free.
- First delivery should store canonical definition versions and evaluate previews on demand with request deadlines and explicit `exact`, `estimated`, `capped` or `stale` count semantics. Exact counts should be used only inside bounded work; an estimate or capped count is preferable for an expensive audience.
- Preview members must be bounded and permission-scoped. Counts, sample rows and explanation details should be separate capabilities and cache entries must include workspace, definition hash, permission/policy revision and evaluation version.
- Asynchronous materialization is a later-safe path for repeated/large evaluations: immutable run identity, definition hash, start/end/evaluation timestamp, cancellation state, expiry/retention, and no membership reuse across workspace or version.
- Cache is an optimization, not authority. Invalidate or mark stale when definition, tenant, relevant source data, consent/policy, or permission scope changes.
- Indexes should follow measured filter/join patterns and tenant-leading keys where justified. Review migrations for online/lock and rollback safety; do not add speculative indexes before examining representative plans.

### UX findings

- Lead with a visual rule builder that describes subject, field, operator, value and Boolean grouping. Make nested logic and event windows visible in plain language.
- Keep an AI-generated definition in a reviewable draft. Show every proposed field/event as a named rule with an explanation, highlight unsupported/ambiguous criteria, and require explicit user confirmation before saving/publishing.
- Distinguish dynamic definition from static membership snapshot, count freshness, estimated versus exact count, and exclusions due to consent/suppression without identifying people.
- Provide accessible keyboard operation, screen-reader labels, responsive layouts, inline validation, loading/empty/stale/timeout/large-audience states and a recoverable cancellation path.
- Save/publish, preview and activation have separate authority. PHASE-09 journey enrollment/execution remains outside this task.

## Conflicts with current assumptions

1. The preplanned plan reserved TASK-0042 for PHASE-07 certification. Canonical main shows PHASE-07 was accepted by TASK-0041 final acceptance PR #405 and merged; TASK-0042 is absent from the task index. This is stale plan text, not unfinished product work.
2. The preplanned PHASE-08 concept does not yet spell out distinct consent/suppression authority, count-disclosure policy, strict AST bounds, registry-only SQL identifiers or evaluation freshness semantics. These are added as acceptance requirements below without changing completed tasks.
3. No product AI Gateway module appears in the current source tree; the repository has an AI Gateway contract and model/prompt registries. TASK-0045 must integrate through that contract or a narrow provider-neutral port, use structured proposals only, and show an unavailable state when no approved model route exists. Do not implement a provider-specific model client, secrets, or PHASE-10 gateway infrastructure here.
4. Repository state files are snapshots: `CURRENT-STATE.yaml` and the checkpoint at main still record PHASE-07 as the prior observed anchor. This task transition will explicitly advance canonical state and preserve the observed main SHA as the research baseline.

## Required roadmap reconciliation

| Classification | Finding | Plan impact |
|---|---|---|
| `CONFIRMS_PLAN` | Versioned segment definitions, deterministic compiler, AI proposal, preview/count and certification remain aligned with TASK-0043–0047. | Keep preplanned scope and dependency order. |
| `NEW_ACCEPTANCE_CRITERION` | Tenant scope outside AST, closed field/operator/join registries, bounded query work, permission-aware aggregate preview, explicit freshness and sending-eligibility recheck. | Add to TASK-0044–0047 criteria before implementation. |
| `NEW_PREREQUISITE` | TASK-0045 uses the existing AI Gateway contract/model policy if an approved route exists; otherwise the provider-neutral port returns an explicit unavailable response. | No direct provider client or phase-10 infrastructure. |
| `ADR_REQUIRED` | Any sensitive/inferred-trait targeting, raw SQL/JSON-path escape hatch, or direct AI-provider call that bypasses the gateway. | Not part of PHASE-08 acceptance. |
| `DEFER_WITH_APPROVAL` | Unbounded export, arbitrary event-property paths, streaming membership and journey activation. | Keep out of PHASE-08. |
| `NO_PRODUCT_IMPACT` | Competitor-specific storage and proprietary scheduling choices. | Do not copy their internal models. |

## Explicit TASK-0042 plan-drift reconciliation

- TASK-0042 remains unmaterialized; do not create it, reuse its identifier, or rewrite historical task numbers.
- PHASE-07 certification is satisfied by TASK-0041 AC-1..AC-8 final acceptance and PR #405 (accepted source `30f7eda14c0589e8e75ced004642251046cfe09c`, protected-main merge `87e65b1d458a79f925376d4cf49792d3771ef192)); the resulting-main state was reconciled by `6d0269bfe9b44b0623fbe1eb0e4d59fd1462e115`.
- TASK-0042 is therefore a documented gap in a stale reservation, not an outstanding acceptance. Preserve it as a gap. PHASE-08 starts at TASK-0043, dependent on completed TASK-0041.
- The stale reservation is marked in the preplanned roadmap and PHASE-07 record; completed history is unchanged.

## Decision impact

- Decision: `CONFIRM_AND_EXTEND_PLAN`.
- TASK-0043 is complete after this research pack and repository registration carrier pass the canonical state, journal, supervisor, policy, parallel, context, and full CI checks.
- TASK-0044 is activated for architecture freeze. Product implementation is gated on its registered acceptance contract.
- PHASE-09 remains planned/inactive; no journey graph, trigger, action or enrollment authority is added.

## Freshness risks

- Laws and regulator guidance vary by jurisdiction and can change; product implementation is not a substitute for jurisdiction-specific legal review.
- Competitor docs and AI provider schema behavior may change. Revalidate at TASK-0045/0046 implementation if source claims affect live behavior.
- PostgreSQL 18 docs are a current design reference, not proof of the database version deployed by this repository. Verify the configured/tested version before choosing indexes, RLS setup or query-plan thresholds.
