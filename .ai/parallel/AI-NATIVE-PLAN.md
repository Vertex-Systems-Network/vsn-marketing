# AI-Native Parallel Plan — TASK-0041 Accelerated Shipping

Status: **promotion verification / staged worker activation**.

Supervisor: `supervisor-main`  
Control branch: `control/task0041-shipping-acceleration`  
Parent task: `TASK-0041`  
Protected-main baseline: `96c4e47d4d02c756fabbfda288d6768c366dfc75`  
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
| 10 | WS-0041-SUPERVISOR-CONTROL | Promote trusted #387 implementation, own shared integration/state/routes and protected-main certification. | `occupied` | `supervisor-main` | `promotion_verification` | `control/task0041-shipping-acceleration` | squash | merge latest main before resume |
| 20 | WS-0041-RETRY-CAPABILITY | Capability-gated publication retry eligibility/execution with exact exclusion and stale-authority safeguards. | **OPEN** | — | `waiting_for_promotion_baseline` | `worker-1/task0041-retry-capability` | squash | merge latest main before resume |
| 30 | WS-0041-APPROVAL-REVOCATION | Revocation/material-change operator commands with server-derived authority and optimistic concurrency. | **OPEN** | — | `waiting_for_promotion_baseline` | `worker-2/task0041-approval-revocation` | squash | merge latest main before resume |
| 40 | WS-0041-PROVIDER-DRIFT | Secret-safe actionable provider disconnect/capability/rate/circuit projections. | **OPEN** | — | `waiting_for_promotion_baseline` | `worker-3/task0041-provider-drift` | squash | merge latest main before resume |
| 50 | WS-0041-OPERATOR-UX-CERT | Accessibility, responsive/error/concurrency UX and focused frontend/E2E certification. | **OPEN** | — | `waiting_for_promotion_baseline` | `worker-4/task0041-operator-ux-cert` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Integration order

1. Promote trusted #387 product behavior onto current protected-main lineage with fresh full exact-head gates.
2. Preserve old `ship/week-1` at an archive ref, then realign the integration branch to resulting protected main only after comparison proves no trusted product loss.
3. Fast-forward the four pre-created worker branches to the same trusted integration baseline.
4. Onboard/lease independent lanes only after promotion is trusted. Worker PRs target `ship/week-1` and use Shipping Fast Gate; dependent work consumes only green integration heads.
5. Full protected-main Application + Security certification remains mandatory at promotion boundaries.
6. Final TASK-0041 acceptance and TASK-0042 activation remain separate guarded milestones.

## Exact next action

Verify the promotion carrier on an unchanged exact head with AI Continuity Guard, Application Foundation CI and Security Supply Chain CI. Merge only if all required gates are green and review is clean. Then preserve historical shipping head, realign `ship/week-1` and worker branches to trusted resulting main, and activate four independent TASK-0041 lanes without widening provider, deployment or deferred Runner authority.
