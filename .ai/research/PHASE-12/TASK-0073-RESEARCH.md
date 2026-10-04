# TASK-0073 Research Pack

- researched_at: 2026-10-03T20:45:00Z
- task: TASK-0073
- phase: PHASE-12
- scope: source reconciliation and quality monitors
- researcher: Supervisor

## Sources
| Source | Type/version | Accessed | Impact |
|---|---|---|---|
| https://www.postgresql.org/docs/18/transaction-iso.html | Upstream PostgreSQL18 |2026-10-03|Read committed multi-query reads can differ; serialize projection/quality checkpoint and use a fixed input list, unique identity and retries.|
| https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference | Official GA protocol |2026-10-03|Receipt success is not processed validity; never infer completeness from locally admitted count.|

## Current external reality
Independent source identity/checkpoint evidence is needed for completeness. Local received, eligible, projected and conflicted counts differ. Event-time window and receipt cutoff are independent.

## Market/reference workflow
Retain existing analytics operator definition/freshness/quality displays. Expose discrepancies and affected metric versions instead of a false green total.

## Security/privacy findings
Threats: forged manifests, cross-brand keys, raw recipient references, replay mutation, retention/consent revival. An explicitly composed verifier checks the complete checkpoint fingerprint, scope, source, period and expected keys; default composition denies verified-source claims. Store only key/hash references. Current privacy checks gate diagnostics and reads. No public manifest-upload endpoint.

## API/platform constraints
No GA or production adapter introduced. The source-verifier port requires a future independently reviewed provider binding; synthetic test bindings are explicitly fixtures. Unknown totals stay null.

## Performance/reliability findings
Bound1000 observations,31-day window. Workspace serialization and unique request identity give atomic immutable receipt/report and retry. A replay with altered evidence is rejected, not overwritten. Capture required PostgreSQL contention and interrupted transaction tests.

## Conflicts with current assumptions
Canonical facts do not prove source completeness. Existing completed tasks remain intact.

## Required roadmap extensions
None; fulfills TASK0073 AC1–3. Separate manifest checkpoint coverage from source truth; do not relabel existing snapshots.

## Rejected options
No matching-local-count certification, raw payload exports, automatic repair/reprojection, mutable historical reports, new store or implicit provider.

## Decision impact
CONFIRMS_PLAN: bounded scoped reconciliation, lag/duplicates/conflict/missing/drift, immutable replay. NO_PRODUCT_IMPACT: upstream GA semantics are references only.

## Freshness risks
A live source verifier/connector requires separate current provider and privacy evidence.
