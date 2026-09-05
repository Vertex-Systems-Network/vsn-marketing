# TASK-0018 — PHASE-03 Acceptance Evidence Map

## Purpose

This worker-owned evidence map links TASK-0018 certification criteria to already-accepted PHASE-03 implementation evidence and to the new certification lanes. It does not close TASK-0018 or mutate canonical task/state files; final exact-head acceptance remains Supervisor-owned.

## Baseline

- TASK-0014 established repository and software-supply-chain governance.
- TASK-0015 established canonical provider, connection, capability and quota persistence/readiness semantics.
- TASK-0016 established provider-neutral connector, normalized error, quota, webhook and reconciliation contracts.
- TASK-0017 delivered Amazon SES, Brevo and Gmail reference connectors plus the cross-provider contract matrix.
- Protected main `4caa654426397473d21d382a8e4d3bcf43057546` is the accepted TASK-0017 closeout baseline used to stage TASK-0018.

## Acceptance mapping

| TASK-0018 criterion | Evidence source | Certification lane | Status in this evidence map |
|---|---|---|---|
| AC-1 prior TASK-0014..0017 evidence remains valid | `.ai/tasks/TASK-0014.yaml` through `TASK-0017.yaml`, execution journal, accepted main history | Evidence Map + Supervisor | Baseline mapped; final exact-head revalidation remains pending |
| AC-2 provider-neutral behavior | `app/Modules/Providers/Application/**`, `app/Modules/Providers/Domain/**`, existing architecture tests | Neutrality | Dedicated PHASE-03 architecture regression required |
| AC-3 workspace isolation/readiness fail-closed | `tests/Feature/Providers/ProviderFoundationTest.php`, `tests/Integration/ProviderFoundationTest.php` | Isolation | Dedicated integrated PHASE-03 certification required |
| AC-4 webhook/error/quota/reconciliation/reference adapters | TASK-0016 connector contracts, TASK-0017 provider tests and `ConnectorMatrix` fixtures/tests | Connector Cert | Runtime manifest ↔ accepted matrix certification required |
| AC-5 repository/security/supply-chain controls | TASK-0014 security controls, ruleset evidence and security workflows | Security Cert | Dedicated PHASE-03 security evidence review required |
| AC-6 no future-phase scope pull-forward | TASK-0017 matrix explicitly marks `routing.failover` and `social.publish` unsupported; Gmail remains mailbox-only | Connector Cert + Supervisor | Baseline evidence present; final diff review required |
| AC-7 full exact-head gates and transactional continuity | `.github/workflows/*`, `.ai/state/*`, `tools/ai_*` validators | Supervisor | Pending final TASK-0018 acceptance head |

## Non-negotiable boundaries

- No PHASE-04 routing/failover implementation is certified or introduced here.
- No PHASE-05 deliverability implementation, PHASE-06 asset studio, PHASE-07 publishing engine, or PHASE-13 social/community implementation is pulled forward.
- Provider-specific behavior remains inside connector infrastructure; Application/Domain layers must stay capability-driven.
- Authentication/readiness, sandbox acceptance, quota evidence and delivery outcome remain distinct concepts.
- Cross-workspace access must fail closed at application and persistence boundaries.
- Ambiguous external dispatch must reconcile rather than blindly retry where provider-native idempotency is not proven.

## Final acceptance ownership

This document is supporting evidence only. TASK-0018 may be marked complete only by the Supervisor after all worker PRs are merged, all remaining branches have synchronized latest `main`, the final change set is audited, and every required exact-head CI/security/continuity gate is green.
