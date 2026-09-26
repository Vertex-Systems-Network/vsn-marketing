# AI-Native Parallel Plan — TASK-0041 Accelerated Shipping

Status: **PHASE-07 final acceptance — verifying protected-main gates; all TASK-0041 workstreams terminal and no active worker leases**.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task0041-phase07-final-acceptance`  
Promotion PR: `#391` — merged as `8bbda80bb34423cdd4d2f42f64c7b3d182dde18f`  
Archived pre-promotion integration head: `archive/ship-week-1-task0041-pr387` -> `df1d117ed78ca3563780444f46fcb316533f8b53`  
Current integration branch: `ship/week-1` -> `0733c40eead4038e2c1d7f19a50df23b88aaac6a`  Parent task: `TASK-0041`  
Protected-main baseline: `ed7644bddabfe9eea4128a3c607e9cb2c9d1a20e`  
Trusted shipping source: PR #387 exact head `acf19cb685923617d1ded39de70ec5af78e96125`, merged on `ship/week-1` as `df1d117ed78ca3563780444f46fcb316533f8b53`  
Shipping writer cap: `5`  
Planned worker lanes: `4` + Supervisor  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Acceleration goal

Use the existing Week-1 Shipping Mode as the normal TASK-0041 feedback path instead of creating overlapping direct-main implementations. Worker lanes stay file-disjoint and cannot write Supervisor-owned routes, global state, workflows, configuration, migrations or release authority. The Supervisor owns integration and protected-main promotion.

The target is higher coding throughput through independent lanes while keeping one trusted integration baseline and unchanged exact-head security/application requirements. No speed optimization may weaken workspace isolation, permission/capability checks, immutable snapshot authority, optimistic concurrency, secret handling, provider-policy boundaries or audit provenance.

## Trusted promotion evidence

- PR #386 remains the protected-main operator read-model/preview foundation at `792881f5c702ee38fa12b066f2eb8f65e73baca3` with RBT-037 PASS.
- PR #387 exact source `acf19cb685923617d1ded39de70ec5af78e96125` passed Shipping Fast Gate `36074285668`.
- Resulting `ship/week-1` head `df1d117ed78ca3563780444f46fcb316533f8b53` passed AI Continuity Guard `36074471199`, Application Foundation CI `36074471168`, and Shipping Fast Gate `36074471196`.
- The promotion carrier copies the reviewed #387 product/test surfaces onto the current protected-main lineage and must independently pass exact-head AI Continuity Guard, Application Foundation CI and Security Supply Chain CI before merge.
- Failed duplicate PR #390 is not promotion evidence and must not be merged.

## Safety boundaries

- Production provider credentials/API calls and provider publish/retry/edit/delete side effects stay inactive unless a later canonical TASK-0041 milestone explicitly authorizes a bounded operation.
- Browser/UI state never grants backend authority.
- Shared routes, global control/state, workflow, dependency, config and migration paths remain Supervisor-owned.
- RBT-004 remains authorization-blocked; CodeQL PRs #235/#388 remain deferred under RBT-005.
- Runner performance/cache/sizing work remains deferred to the coordinated benchmark batch.
- TASK-0042 and deployment/release authority remain inactive.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0041-SUPERVISOR-CONTROL | Promote trusted PR #387 shipping implementation onto current protected-main lineage, own shared routes/control/state integration, reconcile RBT evidence, protect security boundaries, and integrate worker submissions without widening provider or deployment authority. | `occupied` | `supervisor-main` | `promotion_complete` | `control/task0041-shipping-acceleration` | squash | merge latest main before resume |
| 20 | WS-0041-RETRY-CAPABILITY | Implement capability-gated retry preflight/execution over canonical publication attempts with exact eligible/excluded counts, already-successful exclusion, workspace isolation, stale capability fail-closed behavior and no raw provider credential exposure. | **OPEN** | — | `shipping_merged_green` | `worker-1/task0041-retry-capability` | squash | latest green ship/week-1 before submission |
| 30 | WS-0041-APPROVAL-REVOCATION | Implement focused approval revocation/material-change operator command support with server-derived current authority, exact snapshot/state-version concurrency checks and append-only audit provenance; shared route wiring remains Supervisor-owned. | **OPEN** | — | `shipping_merged_green` | `worker-2/task0041-approval-revocation` | squash | latest green ship/week-1 before submission |
| 40 | WS-0041-PROVIDER-DRIFT | Extend the operator read model with actionable non-secret provider disconnect, permission, capability-drift, rate-limit and circuit outcomes while preserving canonical VSN authority and partial-success semantics. | **OPEN** | — | `shipping_merged_green` | `worker-3/task0041-provider-drift` | squash | latest green ship/week-1 before submission |
| 50 | WS-0041-OPERATOR-UX-CERT | Complete accessible responsive operator UX states, keyboard/destructive affordances, loading/empty/error/concurrency feedback and focused frontend/E2E certification without adding backend authority. | **COMPLETE** | — | `shipping_merged_green` | `worker-4/task0041-operator-ux-cert` | squash | terminal; no active lease |
<!-- WORKSTREAM_TABLE_END -->

## Development Acceleration v2.7 overlay

This task inherits the repository-wide wave acceleration contract:

1. batch dependency-ready disjoint leases into one control carrier when distinct real agents are available;
2. do not insert a protected-main control PR between every independent sibling lane by default;
3. allow already-leased independent coding to continue from the last green shipping baseline while a newer sibling integration head is verifying;
4. require synchronization to the latest required green `ship/week-1` baseline before worker submission/merge or dependency consumption;
5. carry terminal worker evidence into the next substantial wave-control/promotion carrier;
6. concentrate full protected-main Application + Security certification at promotion/final-acceptance/security boundaries;
7. never fake agents, overlap write paths, bypass permissions/security, or treat pending/failed integration as consumable.
## Integration order

1. Promote trusted #387 product behavior onto current protected-main lineage with fresh full exact-head gates.
2. Preserve old `ship/week-1` at an archive ref, then realign the integration branch to resulting protected main only after comparison proves no trusted product loss.
3. Fast-forward the four pre-created worker branches to the same trusted integration baseline.
4. Onboard/lease dependency-ready disjoint lanes in wave-sized control carriers when real distinct agents are available. Worker PRs target `ship/week-1` and use Shipping Fast Gate.
5. Independent leased lanes may continue coding from the last green integration baseline while sibling integration verification runs, but submission/merge and dependency consumption require synchronization to the latest required green integration head.
6. Carry sibling terminal evidence forward and avoid per-lane protected-main control PRs unless a safety/authority/drift exception requires one.
7. Full protected-main Application + Security certification remains mandatory at promotion/final-acceptance boundaries.
8. Final TASK-0041 acceptance and TASK-0042 activation remain separate guarded milestones.

## Exact next action

TASK-0041 final acceptance evidence is assembled. Promote PHASE-07 only when this carrier's unchanged exact head passes AI Continuity Guard, Application Foundation CI, Security Supply Chain CI and review. Keep PHASE-08 and TASK-0042 unmaterialized; their research-first registration is a separate milestone.
