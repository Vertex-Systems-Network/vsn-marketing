# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — Railway reconciliation capture fail-closed repair under external-evidence hold**. TASK-0024 remains blocked until one exact benchmark source produces validated delivery + reconciliation evidence and the human Delivery owner explicitly approves the measured numeric threshold set. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-reconciliation-capture-activate`  
Trusted baseline: `99d61d47e9122e4db17e55ead9d3bd7326000b48`  
Latest hosted measurement source: `99d61d47e9122e4db17e55ead9d3bd7326000b48` — delivery evidence valid, reconciliation pair incomplete  
Historical delivery fingerprint: `507baee91d0adf32427082ee9e8849f3a4e70a597859d9d607feb5446aa00f24` — diagnostic only; must not be paired with a repaired descendant source  
Next benchmark source: **pending reconciliation capture repair merge; pin the resulting exact descendant and re-capture both scenarios**  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issues #134, #149, #162 and #165  
Completion signal: `Work Done and Submitted`

The user's authorization covers the dedicated production-representative non-production Railway benchmark environment and capture. It does not pre-approve unknown numeric thresholds. No AI agent may invent evidence, infer or round thresholds, impersonate the Delivery owner, use GitHub CI wall-clock duration as SLO evidence, or activate PHASE-05 early.

The private Railway project `vsn-marketing-task0024-benchmark` has private PostgreSQL, Redis, and an internal benchmark runner with no public domain. Hosted source attestation is fail-closed. PR #164 added Python 3 to the immutable benchmark image and exact-head continuity/application/security gates passed before merge. Exact source `99d61d47e9122e4db17e55ead9d3bd7326000b48` then ran on Railway deployment `83472117-4ddd-4186-bac3-3e3099cd5cac` with the correct benchmark Dockerfile and restart policy `NEVER`.

That run produced validator-passed delivery evidence with fingerprint `507baee91d0adf32427082ee9e8849f3a4e70a597859d9d607feb5446aa00f24`. Reconciliation did not produce evidence. A reconciliation worker result caused `JsonException: Syntax error` at `tools/task0024_benchmark_capture.php:556` while the parent decoded worker stdout. The uncaught exception was handled in a way that did not propagate a non-zero process status, so the outer shell printed `TASK0024_BENCHMARK_CAPTURE_COMPLETE` despite the missing reconciliation file. The marker is rejected. Because final evidence must share one exact benchmark source and environment identity, the valid delivery document from `99d61d47...` is diagnostic only and both scenarios must be re-captured after repair.

Issue #165 tracks this repair. `WS-0024-BENCHMARK-ENV` is complete. `WS-0024-BENCHMARK-CAPTURE` is reactivated on fresh current-main descendant branch `worker-benchmark-capture-ipc/TASK-0024`, with write scope limited to `tools/task0024_benchmark_capture.php` and `tests/Unit/DeliveryEngine/Task0024BenchmarkCaptureToolTest.php`. The repair must make worker/parent exceptions deterministically non-zero, prevent malformed worker IPC from escaping as an uncaught zero-exit failure, and add regression coverage without weakening source/database/Redis safety or evidence validation.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed `TBD_MEASURED` gates | `worker-1/TASK-0024` | certification contract |
| 20 | WS-0024-BENCHMARK-EVIDENCE | Validate repeated benchmark evidence without inferring thresholds | `worker-2/TASK-0024` | evidence validator + tests |
| 30 | WS-0024-POSTGRES-CERT | PostgreSQL certification | `worker-3/TASK-0024` | PostgreSQL tests |
| 40 | WS-0024-REDIS-CERT | Redis certification | `worker-4/TASK-0024` | Redis tests |
| 50 | WS-0024-PROVIDER-CERT | Provider-neutral certification | `worker-5/TASK-0024` | provider tests |
| 60 | WS-0024-QUEUE-CERT | Queue/rate/quota/backpressure certification | `worker-6/TASK-0024` | queue tests |
| 65 | WS-0024-BENCHMARK-CAPTURE | Fail-closed hosted delivery/reconciliation capture and worker IPC | `worker-benchmark-capture-ipc/TASK-0024` | capture runner + tests |
| 67 | WS-0024-SUSTAINED-EVIDENCE | Enforce full measurement windows | `worker-11-fix/TASK-0024` | validator + dependent tests |
| 70 | WS-0024-RECOVERY-CERT | Recovery/reconciliation certification | `worker-7/TASK-0024` | recovery tests |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded telemetry certification | `worker-8/TASK-0024` | telemetry tests |
| 90 | WS-0024-SECURITY-CERT | Delivery security certification | `worker-9/TASK-0024` | security tests |
| 100 | WS-0024-FINAL-GATE | Complete evidence + approved threshold gate | `worker-10/TASK-0024` | final gate + tests |
| 105 | WS-0024-SOURCE-PINNING | Measured source/final-head separation | `worker-source-pin/TASK-0024` | gate/source-pinning files |
| 107 | WS-0024-BENCHMARK-ENV | Python validator runtime in immutable benchmark image | `worker-benchmark-env-python/TASK-0024` | benchmark Dockerfile + environment contract test |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate capture repair, fresh exact-source re-capture, evidence intake and final closeout | `supervisor/task-0024-reconciliation-capture-activate` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Merge this control transaction with fresh exact-head continuity/application/security checks.
2. On `worker-benchmark-capture-ipc/TASK-0024`, make worker/parent unexpected exceptions fail non-zero and make worker-result decoding fail closed with safe diagnostics; add regression tests for malformed/exceptional worker results and successful worker JSON.
3. Merge the capture repair with exact-head checks. Select the resulting descendant main commit as a fresh benchmark source and create a new immutable `benchmark/TASK-0024-<sha8>` ref.
4. Redeploy the existing private Railway runner to that exact source with private PostgreSQL/Redis, exact source variable, benchmark Dockerfile, and restart policy `NEVER`.
5. Re-capture **both** delivery and reconciliation from scratch on that same source/environment. Use enough offered operations to cover each full 30-second measurement window; do not fake elapsed time and do not infer thresholds.
6. Require both published evidence documents to pass `tools/delivery_benchmark_evidence.py`; preserve exact fingerprints, environment identity and raw observations.
7. Present actual p95/p99/throughput measurements and both fingerprints to the human Delivery owner for explicit numeric approval.
8. Only from that approval, commit evidence and manifest on a descendant final-certification branch, resolve canonical `TBD_MEASURED`, and run the clean-checkout TASK-0024 certification gate plus every exact-head CI/release/continuity check.
9. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025/PHASE-05 activate.
