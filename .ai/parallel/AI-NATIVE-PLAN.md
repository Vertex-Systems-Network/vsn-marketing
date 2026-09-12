# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active / external-evidence hold** — TASK-0024 remains the canonical PHASE-04 certification task. All repository certification, benchmark-capture, final-gate, and sustained-measurement hardening lanes are merged on `main`. No additional product implementation is authorized while the remaining AC-4 evidence and approval requirements are external.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-external-evidence`  
Trusted baseline: `6af2f5cf91d929b48d2c533a56b3c6af0d8cf430`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

TASK-0023 is complete, but PHASE-04 is not certified. The canonical TASK-0023 SLO contract still keeps environment-sensitive queue-age, end-to-end latency, sustainable-throughput and reconciliation-lag thresholds as `TBD_MEASURED`. TASK-0024 therefore remains fail-closed until reproducible production-representative benchmark evidence exists and a human Delivery owner explicitly approves the numeric threshold set and pinned evidence revisions. Hosted-CI wall-clock duration is not production SLO evidence.

The benchmark-capture runner may execute the already-implemented TASK-0019 through TASK-0023 delivery/recovery paths only on an explicitly dedicated non-production benchmark environment. It must emit raw observations for validation by `tools/delivery_benchmark_evidence.py`; it may not perform destructive database/Redis resets, infer thresholds, claim external provider/network latency, or generate Delivery-owner approval.

Sustainable-throughput evidence is hardened: every measured run must cover its declared `scenario.measurement_window_seconds`. An operation-limited short burst that finishes earlier is invalid evidence and cannot supply the sustainable-throughput threshold.

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
| 67 | WS-0024-SUSTAINED-EVIDENCE | Reject short-burst evidence that does not cover the declared measurement window and update dependent gate fixtures | `worker-11-fix/TASK-0024` | `tools/delivery_benchmark_evidence.py`, `tools/test_delivery_benchmark_evidence.py`, `tools/test_task0024_certification_gate.py` |
| 70 | WS-0024-RECOVERY-CERT | Retry/ambiguity/breaker/DLQ/reconciliation/duplicate certification | `worker-7/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04RecoveryCertificationTest.php` |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded hotspot/blocking telemetry and tenant isolation | `worker-8/TASK-0024` | `tests/Feature/DeliveryEngine/Phase04TelemetryCertificationTest.php` |
| 90 | WS-0024-SECURITY-CERT | Cross-workspace, redaction and policy-denial security certification | `worker-9/TASK-0024` | `tests/Feature/Security/Phase04DeliverySecurityCertificationTest.php` |
| 100 | WS-0024-FINAL-GATE | Deterministic complete-evidence + approved-threshold gate; `TBD_MEASURED` blocks | `worker-10/TASK-0024` | `tools/task0024_certification_gate.py`, test |
| 110 | WS-0024-CONTROL-ACTIVATION | Maintain fail-closed external-evidence hold and final closeout sequencing | `supervisor/task-0024-external-evidence` | `.ai/parallel/*` control files |
<!-- WORKSTREAM_TABLE_END -->

## External evidence gate

TASK-0024 cannot advance until all of the following exist for one exact source commit and one consistent production-representative non-production environment:

1. validated delivery evidence containing repeated raw `queue_age_ms` and `end_to_end_ms` observations plus sustained throughput;
2. validated reconciliation evidence containing repeated raw `reconciliation_lag_ms` observations;
3. environment identity including PHP/Laravel, PostgreSQL, Redis, OS, CPU and memory;
4. deterministic workload parameters, separated warmup and measurement windows, with every measured run covering its declared window;
5. an explicit human Delivery-owner-approved threshold manifest pinning both benchmark fingerprints and the exact source commit;
6. replacement of all required `TBD_MEASURED` values only from that reviewed threshold set; and
7. a passing `tools/task0024_certification_gate.py` plus all applicable exact-head application/integration/E2E/security/continuity/release checks.

No PHASE-05+ sender-domain/deliverability, content studio, campaign/publishing, journey, or other later-phase implementation is authorized before this gate passes.

## Final closeout

After the external evidence gate passes, the Supervisor must certify AC-1 through AC-7 on the same exact acceptance head and synchronize canonical task index/roadmap, current state, checkpoint and append-only journal transactionally before TASK-0025 can activate.
