# TASK-0068 Research Pack

- researched_at: 2026-10-03T12:59:00Z
- task: TASK-0068
- phase: PHASE-12
- scope: canonical analytics, privacy, funnels, cohorts, attribution, money, reconciliation and scale
- researcher: Supervisor development agent

## Sources

Official pages below were opened through current web retrieval in this session, not inferred from search snippets.

| Source | Type / version | Accessed | Why authoritative / implementation impact |
|---|---|---|---|
| https://www.postgresql.org/docs/18/transaction-iso.html | PostgreSQL 18 transaction isolation | 2026-10-03 | Upstream MVCC semantics; snapshot report reads and race-safe durable dedupe require database coverage. |
| https://www.postgresql.org/docs/18/ddl-constraints.html | PostgreSQL 18 constraints | 2026-10-03 | Unique and composite keys enforce scoped replay invariants; application-only checking is insufficient under concurrency. |
| https://www.postgresql.org/docs/current/sql-refreshmaterializedview.html | PostgreSQL current/18 | 2026-10-03 | Concurrent refresh requires a suitable unique index and serializes per view; do not introduce materialized views or extra stores without measured need. |
| https://amplitude.com/docs/analytics/charts/funnel-analysis/funnel-analysis-build | Current official market workflow | 2026-10-03 | Ordered, any-order and exact-order have different conversion semantics; explicitly implement a named supported mode with a bounded conversion window. |
| https://amplitude.com/docs/analytics/charts/retention-analysis/retention-analysis-build | Current official market workflow | 2026-10-03 | Retention compares declared start and return events; cohort eligibility and complete observation horizon must be disclosed. |
| https://support.google.com/analytics/answer/10596866 | Current official attribution methodology | 2026-10-03 | Attribution allocates credit through a declared model. VSN rule-based credit is descriptive, not causal uplift; never imply parity with data-driven randomized counterfactual training. |
| https://developers.google.com/analytics/devguides/collection/ga4/reference/events | Current event reference | 2026-10-03 | Purchases/refunds reference transaction identity and currency. VSN needs its own canonical integer money model, unique transactions and reversal lineage. No GA adapter is authorized by this task. |
| https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference | Current protocol reference | 2026-10-03 | HTTP receipt can succeed without valid processed measurement. Separate receipt, admitted fact and reconciled totals; unknown source completeness remains unknown. |
| https://support.google.com/analytics/answer/6366371 | Current official privacy engineering guidance | 2026-10-03 | Exclude identifying text and secrets from analytics extracts and AI context. This is engineering guidance, not jurisdictional legal approval. |
| https://www.w3.org/TR/WCAG22/ | WCAG 2.2 | 2026-10-03 | Accessible dashboard forms, tables, status/error states, keyboard/focus and responsive acceptance are mandatory. |

## Current external reality

Analytics systems distinguish the occurrence of an event from when it is ingested. Reporting depends on a stable metric definition, identity, window and source validity. Attribution is a disclosed credit allocation policy; it does not automatically measure incremental treatment effects. Money cannot safely be summed across currencies or represented as binary floating-point billing totals. Provider receipt is not proof of validated event admission or source completeness.

## Market/reference workflow

Amplitude exposes event selection, funnel order and cohort/retention starting and return actions. VSN must expose supported semantics, date/window and freshness instead of a bare conversion percentage. Google exposes attribution-model interpretation; VSN must show model version and unattributed credit. Benchmarking workflow does not authorize copying proprietary implementations or imply market parity.

## Security/privacy findings

Primary threats: cross-workspace/brand aggregate leakage, source identity collisions, malformed revenue events, tracking scope expansion, raw PII in grouping/export/model prompts, stale permission in scheduled jobs, and fabricated AI numeric claims. Use canonical authorization, bounded allowlisted dimensions, no raw customer payload in snapshots/explanations, explicit lawful-purpose/retention gates before projection, and deletion/identity invalidation behavior. No new third-party transfer or live tracking collector is introduced by registration. Region-specific compliance, new data collection and processor activation need separate current evidence.

## API/platform constraints

No external provider SDK or analytics collection API is implemented by this task. Existing canonical events are the foundation. Upstream customer event envelope v1 is immutable; dedupe covers event identity and scoped source identity, and conflicting replays are observable rather than overwritten. Production provider reconciliation must never invent a source total from local counts.

## Performance/reliability findings

Retain PostgreSQL 18 and the canonical modular monolith. Define bounded report windows/cardinality, explicit hard failure when bounds are exceeded, snapshot consistency, query count/latency SLIs and a reproducible measured acceptance workload. Numeric performance claims and SLO acceptance remain pending until TASK-0074 representative measurements. Late arrivals require a new versioned snapshot or recomputation, never silent historical rewriting. Recovery must replay safely after partial failure.

## Conflicts with current assumptions

The preplan matches the canonical module registry (Analytics and Attribution). Existing Events receipt does not prove analytics-purpose consent, source completeness or verified monetary semantics. Phase 11 offline outcomes do not establish production conversion validity. The research rejects treating attribution as experimental effect or observed LTV as a lifetime forecast.

## Required roadmap extensions

The seven preplanned tasks remain intact. Strengthened acceptance covers source-key conflicts, timestamp ties, incomplete retention horizons, integer currency money/refund conservation, privacy-purpose/retention and identity lifecycle, scheduled report permission rechecks, and explanation fact/reference validation. These are within existing tasks, not replacements or approved deferrals.

## Rejected options

No new ClickHouse/OpenSearch store without measured need and ADR; no raw PII copy for convenience; no silent identity merging; no implicit FX conversion; no AI-generated causal or numeric claims; no automatic model/provider activation; no fabricated reconciliation success from matching local totals.

## Decision impact

CONFIRMS_PLAN: canonical Events → Analytics/Attribution, PostgreSQL, provider-neutral contracts.
NEW_ACCEPTANCE_CRITERION: ordering/horizons, privacy and monetary/reconciliation safeguards above, mapped to TASK-0069–0074.
NO_PRODUCT_IMPACT: GA transport/provider limits are reference evidence only; no GA adapter added.
BLOCKER: none for research/registration. Production validity and measured scale remain unproven acceptance obligations, not task closure evidence.

## Freshness risks

Revalidate external APIs/policies if a live connector or new tracking class is added. Revalidate market/provider semantics before each affected implementation task. Internal domain tests may rely on the dated supported VSN contract; they must not claim unmeasured production behavior.
