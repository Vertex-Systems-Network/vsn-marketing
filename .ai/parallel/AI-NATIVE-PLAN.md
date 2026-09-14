# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — Railway sustained reconciliation benchmark isolation repair under external-evidence hold**. TASK-0024 remains blocked until one exact benchmark source produces validated delivery + reconciliation evidence and the human Delivery owner explicitly approves the measured numeric threshold set. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-reconciliation-breaker-isolation`  
Trusted baseline: `d8db9d5563efbb42662141aa48f96b57bb9f08ef`  
Latest hosted measurement source: `d8db9d5563efbb42662141aa48f96b57bb9f08ef` — delivery evidence valid, reconciliation pair incomplete  
Historical delivery fingerprint: `9128fe47b512f059cb0d850bd5cbad9339809789ee360b1eb70e17f6dc593ee2` — diagnostic only; must not be paired with a repaired descendant source  
Next benchmark source: **pending reconciliation breaker-isolation repair merge; pin the resulting exact descendant and re-capture both scenarios**  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issues #134, #149, #162 and #168  
Completion signal: `Work Done and Submitted`

The user's authorization covers the dedicated production-representative non-production Railway benchmark environment and capture. It does not pre-approve unknown numeric thresholds. No AI agent may invent evidence, infer or round thresholds, impersonate the Delivery owner, use GitHub CI wall-clock duration as SLO evidence, or activate PHASE-05 early.

The private Railway project `vsn-marketing-task0024-benchmark` has private PostgreSQL, Redis, and an internal benchmark runner with no public domain. Hosted source attestation remains fail-closed. PR #167 fixed worker IPC and top-level exception propagation. Exact source `d8db9d5563efbb42662141aa48f96b57bb9f08ef` then ran on Railway deployment `b0b7aa31-e66a-40cd-9df1-709442b6548b` with the benchmark Dockerfile, Python validator and restart policy `NEVER`.

That run produced validator-passed delivery evidence with fingerprint `9128fe47b512f059cb0d850bd5cbad9339809789ee360b1eb70e17f6dc593ee2`. Reconciliation then correctly failed closed with a worker `RuntimeException` at `tools/task0024_benchmark_capture.php:667`, proving PR #167 removed the old false-completion path. Repository analysis identifies the benchmark-harness interaction: each reconciliation operation intentionally records an ambiguous transport outcome on the same synthetic route; production recovery policy carries a shared circuit-breaker failure streak with default threshold 3, and existing recovery certification proves three sequential failure outcomes open that breaker. Reconciliation resolution does not reset that breaker. The sustained benchmark therefore self-blocks instead of measuring the intended reconciliation path across the full declared window.

Issue #168 tracks a benchmark-harness-only repair. Production breaker policy and recovery semantics must remain unchanged. `WS-0024-BENCHMARK-CAPTURE` is retargeted to fresh current-main descendant branch `worker-benchmark-reconciliation-isolation/TASK-0024`, with write scope limited to `tools/task0024_benchmark_capture.php` and `tests/Unit/DeliveryEngine/Task0024BenchmarkCaptureToolTest.php`. The repair must deterministically isolate repeated reconciliation measurements from synthetic breaker accumulation while preserving the real recovery + evidence-backed reconciliation application path, exact source/database/Redis safety, validator enforcement, full-window enforcement, and no threshold inference or approval generation.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed `TBD_MEASURED` gates | `worker-1/TASK-0024` | certification contract |
| 20 | WS-0024-BENCHMARK-EVIDENCE | Validate repeated benchmark evidence without inferring thresholds | `worker-2/TASK-0024` | evidence validator + tests |
| 30 | WS-0024-POSTGRES-CERT | PostgreSQL certification | `worker-3/TASK-0024` | PostgreSQL tests |
| 40 | WS-0024-REDIS-CERT | Redis certification | `worker-4/TASK-0024` | Redis tests |
| 50 | WS-0024-PROVIDER-CERT | Provider-neutral certification | `worker-5/TASK-0024` | provider tests |
| 60 | WS-0024-QUEUE-CERT | Queue/rate/quota/backpressure certification | `worker-6/TASK-0024` | queue tests |
| 65 | WS-0024-BENCHMARK-CAPTURE | Sustained reconciliation breaker isolation; hosted fail-closed capture | `worker-benchmark-reconciliation-isolation/TASK-0024` | capture runner + tests |
| 67 | WS-0024-SUSTAINED-EVIDENCE | Enforce full measurement windows | `worker-11-fix/TASK-0024` | validator + dependent tests |
| 70 | WS-0024-RECOVERY-CERT | Recovery/reconciliation certification | `worker-7/TASK-0024` | recovery tests |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded telemetry certification | `worker-8/TASK-0024` | telemetry tests |
| 90 | WS-0024-SECURITY-CERT | Delivery security certification | `worker-9/TASK-0024` | security tests |
| 100 | WS-0024-FINAL-GATE | Complete evidence + approved threshold gate | `worker-10/TASK-0024` | final gate + tests |
| 105 | WS-0024-SOURCE-PINNING | Measured source/final-head separation | `worker-source-pin/TASK-0024` | gate/source-pinning files |
| 107 | WS-0024-BENCHMARK-ENV | Python validator runtime in immutable benchmark image | `worker-benchmark-env-python/TASK-0024` | benchmark Dockerfile + environment contract test |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate breaker-isolation repair, fresh exact-source re-capture, evidence intake and final closeout | `supervisor/task-0024-reconciliation-breaker-isolation` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Merge this control transaction with fresh exact-head continuity/application/security checks.
2. On `worker-benchmark-reconciliation-isolation/TASK-0024`, isolate the repeated reconciliation benchmark from synthetic per-fixture circuit-breaker accumulation without modifying production breaker defaults or recovery/reconciliation application code; add regression tests proving the benchmark-specific isolation contract.
3. Merge the capture repair with exact-head checks. Select the resulting descendant main commit as a fresh benchmark source and create a new immutable `benchmark/TASK-0024-<sha8>` ref.
4. Redeploy the existing private Railway runner to that exact source with private PostgreSQL/Redis, exact source variable, benchmark Dockerfile, and restart policy `NEVER`.
5. Re-capture **both** delivery and reconciliation from scratch on that same source/environment. Use enough offered operations to cover each full 30-second measurement window; do not fake elapsed time and do not infer thresholds.
6. Require both published evidence documents to pass `tools/delivery_benchmark_evidence.py`; preserve exact fingerprints, environment identity and raw observations.
7. Present actual p95/p99/throughput measurements and both fingerprints to the human Delivery owner for explicit numeric approval.
8. Only from that approval, commit evidence and manifest on a descendant final-certification branch, resolve canonical `TBD_MEASURED`, and run the clean-checkout TASK-0024 certification gate plus every exact-head CI/release/continuity check.
9. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025/PHASE-05 activate.
