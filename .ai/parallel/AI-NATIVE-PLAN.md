# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active** — TASK-0024 is the canonical PHASE-04 certification task. Eleven disjoint worker lanes are activated; the original ten certification lanes landed from trusted main `6d98c0a77f1b7e97b0acf7ee8aba3778e90e17b2`, and a narrowly scoped benchmark-capture lane was added after closeout audit showed that the repository had evidence validation and deterministic workload generation but no operator-safe production-representative capture runner.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-certification`  
Trusted baseline: `6d98c0a77f1b7e97b0acf7ee8aba3778e90e17b2`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

TASK-0023 is complete, but PHASE-04 is not certified. The canonical TASK-0023 SLO contract intentionally keeps environment-sensitive queue-age, end-to-end latency, sustainable-throughput and reconciliation-lag thresholds as `TBD_MEASURED`; TASK-0024 must remain fail-closed until reproducible benchmark evidence exists and the Delivery owner approves a numeric threshold set. Hosted-CI wall-clock duration is not a production SLO and may not be promoted into one.

The pre-existing automated TASK-0024 pull requests were generated before TASK-0023 closeout and contain stale duplicated state plus fabricated/provider-specific numeric assumptions. They are not accepted as evidence. Each registered worker branch must be reconstructed from trusted/current main and retain only its scoped certification deliverable.

The benchmark-capture lane is operational evidence tooling only. It may execute the already-implemented TASK-0019 through TASK-0023 delivery/recovery paths on an explicitly dedicated non-production benchmark environment and emit raw observations in the schema consumed by `tools/delivery_benchmark_evidence.py`. It may not run destructive database reset/Redis flush operations, may not claim that GitHub-hosted CI is production-representative, may not infer thresholds, and may not provide or impersonate Delivery-owner approval.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed `TBD_MEASURED` gates | `worker-1/TASK-0024` | `docs/operations/TASK-0024-CERTIFICATION.md` |
| 20 | WS-0024-BENCHMARK-EVIDENCE | Validate/aggregate repeated benchmark evidence without inferring thresholds | `worker-2/TASK-0024` | `tools/delivery_benchmark_evidence.py`, test |
| 30 | WS-0024-POSTGRES-CERT | PostgreSQL durability/contention/rollback/workspace certification | `worker-3/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04PostgresCertificationTest.php` |
| 40 | WS-0024-REDIS-CERT | Redis latency/interruption/lease recovery certification | `worker-4/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04RedisCertificationTest.php` |
| 50 | WS-0024-PROVIDER-CERT | Provider-neutral fault/failover certification | `worker-5/TASK-0024` | `tests/Integration/Providers/Phase04ProviderCertificationTest.php` |
| 60 | WS-0024-QUEUE-CERT | Queue/rate/quota/backpressure certification plus measured evidence | `worker-6/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04QueueCertificationTest.php` |
| 65 | WS-0024-BENCHMARK-CAPTURE | Operator-safe repeated raw queue/E2E/reconciliation benchmark capture on a dedicated production-representative environment; no threshold inference | `worker-12/TASK-0024` | `tools/task0024_benchmark_capture.php`, `tests/Unit/DeliveryEngine/Task0024BenchmarkCaptureToolTest.php` |
| 70 | WS-0024-RECOVERY-CERT | Retry/ambiguity/breaker/DLQ/reconciliation/duplicate certification | `worker-7/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04RecoveryCertificationTest.php` |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded hotspot/blocking telemetry and tenant isolation | `worker-8/TASK-0024` | `tests/Feature/DeliveryEngine/Phase04TelemetryCertificationTest.php` |
| 90 | WS-0024-SECURITY-CERT | Cross-workspace, redaction and policy-denial security certification | `worker-9/TASK-0024` | `tests/Feature/Security/Phase04DeliverySecurityCertificationTest.php` |
| 100 | WS-0024-FINAL-GATE | Deterministic complete-evidence + approved-threshold gate; `TBD_MEASURED` blocks | `worker-10/TASK-0024` | `tools/task0024_certification_gate.py`, test |
| 110 | WS-0024-CONTROL-ACTIVATION | Activate/amend registry and leases only | `supervisor/task-0024-certification` | `.ai/parallel/*` control files |
<!-- WORKSTREAM_TABLE_END -->

## Parallel execution rules

1. Control activation/amendment lands before a newly registered worker performs writable work. Worker branches are reconstructed from current `main` without force-push, preserving only registered write-scope changes.
2. A worker may certify or benchmark an invariant already implemented by TASK-0019 through TASK-0023, but it may not add later-phase product capability just to make certification pass.
3. No worker may invent provider-specific routes, fixed queue depth, TPS, connection-count, recovery-time, percentile or regression thresholds. Numeric acceptance thresholds require reproducible measured evidence plus Delivery-owner approval.
4. `TBD_MEASURED`, missing evidence, malformed evidence, environment mismatch and threshold violation are blocking outcomes, never passes.
5. Worker PR bodies must contain standalone `Workstream: <ID>` and may add standalone `Work Done and Submitted` only when their scoped evidence/tooling is complete.
6. Every merge is followed by the issue #43 broadcast and remaining branches must merge current main before resuming.
7. No PHASE-05+ sender-domain/deliverability, content studio, campaign/publishing, journey or other later-phase implementation is authorized.
8. Benchmark capture must fail closed unless the operator explicitly identifies a dedicated non-production benchmark environment. The capture tool must not run `migrate:fresh`, flush Redis, or otherwise reset shared infrastructure.
9. Benchmark capture emits observations only. Delivery-owner threshold approval remains a separate human decision and is not generated by tooling.

## Final closeout

TASK-0024 can complete only after AC-1 through AC-7 are evidence-backed on one exact acceptance head, all required application/integration/E2E/security/continuity gates are green, the approved numeric performance threshold set is no longer unresolved, and canonical state/checkpoint/journal are synchronized transactionally.
