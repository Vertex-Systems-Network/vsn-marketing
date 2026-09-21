# Runner Task Benchmark Backlog

Status: **deferred registry — collect now, execute as one coordinated runner batch later**

Owner: Supervisor control plane  
Workstream: `WS-0036-SUPERVISOR-CONTROL`  
Execution policy: runner tasks are deferred by default and executed in one coordinated batch; only the explicit blocker-escalation exception below permits earlier execution.  
Security policy: existing branch protection, exact-head CI, action pinning, dependency thresholds, secret scanning, container scanning, and benchmark-environment isolation remain mandatory.
Interaction policy: CI status polling and resume behavior follow `docs/operations/AI-EXECUTION-RESILIENCE.md`; timeout avoidance never activates the Runner batch or weakens a gate.

## Purpose

This file is the persistent benchmark/backlog for work whose primary subject is a CI/automation runner, runner resource profile, runner/toolchain performance, or production-representative benchmark runner. Product and security development continue normally while runner work accumulates here. When the runner batch is explicitly activated, items are executed together so measurements are comparable and changes do not drift across unrelated feature work.

## CI execution is not Runner benchmark execution

A normal GitHub Actions job running on a hosted runner is **not** an RBT task. Required CI verifies correctness/security; RBT items measure or optimize runner size, architecture, cache, concurrency, topology, toolchain performance, or benchmark environments.

The strict change-aware policy is:

- control/docs/research-only diffs use lightweight governance and job-level skipped-success for heavy required jobs unless `CI-Mode: full` is present;
- product/dependency/workflow/tool/security-sensitive diffs run the full required CI set;
- certification/release/security acceptance may force full CI with `CI-Mode: full` even when its diff is control-only;
- running required CI never changes an RBT row from `deferred`;
- discovering a runner optimization during normal CI appends/updates an RBT item instead of executing the optimization;
- the coordinated Runner batch remains dormant until explicitly activated, and unrelated RBT items must not be pulled forward.

## Measurement contract

Every executed item must record the exact source SHA, workflow/tool revision, runner image/architecture/size, relevant service versions, cold/warm cache state, concurrency, elapsed job time, queue/start delay when observable, failures/retries, and any runner resource evidence that the platform exposes. Before/after comparisons must use equivalent workloads. Do not infer production SLOs from GitHub-hosted runner timings.

Sensitive values, credentials, recipient data, provider tokens, private payloads, and raw secrets must never be captured in benchmark evidence.

## Deferred items

| ID | Runner scope | Source / trigger | What to measure or decide | Dependencies | Status |
|---|---|---|---|---|---|
| RBT-001 | Application Foundation CI | Current `.github/workflows/application-ci.yml` jobs on `ubuntu-latest` | Baseline foundation, PHP-floor, PostgreSQL/Redis integration, Playwright E2E, frontend test/build duration and runner resource pressure; separate cold/warm dependency setup where observable. | Stable exact-head workload | deferred |
| RBT-002 | Security Supply Chain CI | Current `.github/workflows/security-ci.yml` jobs on `ubuntu-latest` | Baseline CodeQL, PHP Semgrep, dependency audit, repository secret scan, Docker+Trivy container scan, and SBOM reproducibility cost/duration; identify disk-heavy or duplicate setup safely. | RBT-001 measurement schema | deferred |
| RBT-003 | Shipping Fast Gate | Current `.github/workflows/shipping-fast-gate.yml` | Compare fast-gate latency and duplicated dependency/setup work against full application/security CI; preserve its exact-head safety role. | RBT-001/RBT-002 baseline method | deferred |
| RBT-004 | Production-representative delivery benchmark runner | Issue #134 / `tools/task0024_benchmark_capture.php` | Capture the already-required dedicated non-production delivery + reconciliation evidence with exact runner/resource assumptions; keep this evidence separate from GitHub-hosted CI timing. | Authorized benchmark environment, PostgreSQL, Redis, exact source SHA, Delivery-owner threshold approval process | deferred / external environment required |
| RBT-005 | CodeQL runner/toolchain maintenance | Open PRs #235 and #236 | Review pinned CodeQL Action update together with runner effects (bundle/toolcache behavior, disk usage, supported runner architecture) before deciding whether to merge; do not treat release-note claims as local benchmark evidence. | RBT-002 baseline; immutable action pin review | deferred |
| RBT-006 | Runner size / architecture / cache / concurrency evaluation | Derived from all current workflows using `ubuntu-latest` | After baselines exist, compare only justified variants (for example larger runner or ARM64 where supported, cache strategy, job topology/concurrency). Require measured benefit, cost/security review, and no weakening of required checks. | RBT-001 through RBT-005 as applicable | deferred |

## Append rule

Add every newly discovered runner-related task as the next `RBT-###` row. Record its source/trigger and dependencies immediately, and leave it deferred unless the user explicitly activates the coordinated runner batch or the blocker-escalation exception below applies.

## Immediate blocker-escalation exception

A Runner item may be executed before the coordinated batch only when it is demonstrated to block at least one of: security remediation, product correctness, a required exact-head verification gate, or protected-main/release acceptance. The Supervisor must record the evidence and reason on the item, change its status to `escalated-immediate`, keep the scope limited to the blocking condition, and return the item to `completed-immediate` only after the relevant correctness/security gates pass. Performance tuning, convenience, cost optimization, speculative architecture changes, and ordinary CI speed work never qualify for this exception.

An immediate escalation does not activate the rest of the Runner batch. All unrelated `RBT-###` items remain deferred and continue accumulating for the final coordinated execution wave.

## Batch activation gate

The runner batch may start only when explicitly requested. At activation time, snapshot the exact protected-main source/workflow revisions, freeze the item set for the batch, execute baseline measurements first, then test changes one class at a time. Merge only changes whose correctness/security gates remain green and whose measured benefit is documented.
