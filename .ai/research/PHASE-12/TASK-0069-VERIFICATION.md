# TASK-0069 verification matrix — 2026-10-03

This certifies only the analytics fact/count foundation when all required main gates below are accepted. It does not certify PHASE-12 as a whole, dashboard/funnel/revenue features, source completeness, live effectiveness, production scale, regulatory approval or external publication.

| Criterion | Implementation / automated evidence | Boundary |
|---|---|---|
| AC-1 lineage and replay | Canonical Events-only admission, immutable envelope hash, UUID/source unique constraints, source collision quarantine; feature replay/collision/corruption cases | Raw canonical payload is hashed, not copied to derived tables. Missing source ID remains canonical-ID lineage with unknown source completeness. |
| AC-2 scope, purpose and identity | Real WorkspaceAuthorizer; organization/workspace/brand checks; GetEffectiveConsent analytics/measurement; approved retention default disabled; expiry, revocation, HMAC rotation and erasure tombstones covered by feature tests | Explicit invalidation is a repository capability, not an executed production erasure. Existing contact changes that break canonical brand/identity lineage deny reads. No automatic identity merge is inferred. |
| AC-3 deterministic aggregation | Versioned event vs unique-subject counts, half-open UTC windows, trusted receipt cutoff, immutable snapshots, late-arrival new snapshot, exclusion/freshness diagnostics; 1,001-fact hard-bound test | Maximum 1,000 candidate facts and 31-day query window. Receipt freshness is not provider completeness. No silent truncated total. |
| PostgreSQL adversity | `AnalyticsFactsPostgresTest` forked competing workers, committed one-row admission, replay and source conflict, using isolated disposable test namespace | Original cleanup failed against append-only consent; repaired without disabling trigger or weakening old audit-count assertions. |
| Local checks | Latest final-tree regression: 804 passed, 5,303 assertions, 138 explicit infrastructure skips; focused analytics 12 passed / 48 assertions; PHPStan/Pint and continuity/policy/Supervisor/Runner pass | Local skipped infrastructure tests are not PostgreSQL evidence. |

## Exact PR gates

PR #473 final head `a9b78725e0d0e81d9567f3a87dce3e274786a25c`:

- Application 37127322637: foundation111215180179, integration111215645421, PHPfloor111215645480 and E2E111215645545 all success.
- Integration job111215645421 log explicitly shows PASS for `AnalyticsFactsPostgresTest` and its competing-worker case. Suite: 182 passed / 1,152 assertions.
- Security37127322643: all nine security jobs success. Continuity37127322645 success.
- Supervisor37127615726 reconcile111216040873 success; earlier pull-request-triggered run was cancelled and is not a success claim.
- No submitted reviews or unresolved review threads at merge. Expected-head squash merge confirmed.

Resulting protected main `bc052d5ff499a5c31ee372386a29438766d4d885` has the same tree `45b8d9797314e9dd7f073a66516e5a195e326cde` as the verified head. Resulting-main full gates accepted: Application37127706833 foundation111216306866, E2E111216778756, PHPfloor111216778761 and integration111216778764 all success; Security37127706868 all nine jobs success; Continuity37127706896 governance/publish-default success; Release37127706912, Scorecard37127706871 and Supervisor37127869966 reconcile111216793682 success. Supervisor log explicitly checks out main bc052d5. Second consolidated main refresh was required to establish completion of the last integration job before dependency consumption. TASK-0069 acceptance is supported; TASK0070–0074 remain unfinished.
