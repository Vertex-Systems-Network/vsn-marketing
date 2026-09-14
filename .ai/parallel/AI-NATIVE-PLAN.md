# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — hosted benchmark capture source-attestation repair under external-evidence hold**. TASK-0024 remains blocked until real delivery + reconciliation benchmark evidence and explicit human Delivery-owner approval of the measured numeric threshold set are committed and pass final certification. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-capture-attest-activate`  
Trusted baseline: `446abc364bec7c3aea3307d8d5eafa9faa8b60cf`  
Current Railway runtime source: `446abc364bec7c3aea3307d8d5eafa9faa8b60cf`  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issues #134, #149 and #157  
Completion signal: `Work Done and Submitted`

The user's authorization covers a dedicated production-representative non-production benchmark environment and capture. It does not pre-approve unknown numeric thresholds. No AI agent may invent evidence, infer/round thresholds, impersonate the Delivery owner, use GitHub CI wall-clock as SLO evidence, or activate PHASE-05 early.

The private Railway project `vsn-marketing-task0024-benchmark` is provisioned with private PostgreSQL, Redis, and an internal benchmark runner. PR #155 fixed hosted runtime source attestation without weakening local Git HEAD verification. The real Railway deployment `2bc5f4cc-839f-4505-8b25-fec3a4174eb8` reached SUCCESS at source `446abc364bec7c3aea3307d8d5eafa9faa8b60cf`, PostgreSQL/Redis are SUCCESS, and normal forward migrations completed. Issue #154 is closed as verified.

Before measurement, inspection found one remaining hosted incompatibility: `tools/task0024_benchmark_capture.php::task0024VerifyCheckout()` still hard-requires `git rev-parse HEAD`, while the Railway Docker source archive intentionally omits `.git`. Issue #157 tracks the capture-tool repair. `WS-0024-BENCHMARK-CAPTURE` is reactivated with its own lease; `WS-0024-BENCHMARK-ENV` is complete and its lease is released.

Hosted capture source attestation must require the explicit `--commit-sha`, `TASK0024_BENCHMARK_SOURCE_SHA`, and Railway's immutable `RAILWAY_GIT_COMMIT_SHA` to all be full SHAs and exactly equal. Local/non-Railway capture must retain actual Git HEAD equality with `--commit-sha`. The capture fix may not create evidence, thresholds, approvals, or PHASE-05 capability by itself.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed `TBD_MEASURED` gates | `worker-1/TASK-0024` | certification contract |
| 20 | WS-0024-BENCHMARK-EVIDENCE | Validate repeated benchmark evidence without inferring thresholds | `worker-2/TASK-0024` | evidence validator + tests |
| 30 | WS-0024-POSTGRES-CERT | PostgreSQL certification | `worker-3/TASK-0024` | PostgreSQL tests |
| 40 | WS-0024-REDIS-CERT | Redis certification | `worker-4/TASK-0024` | Redis tests |
| 50 | WS-0024-PROVIDER-CERT | Provider-neutral certification | `worker-5/TASK-0024` | provider tests |
| 60 | WS-0024-QUEUE-CERT | Queue/rate/quota/backpressure certification | `worker-6/TASK-0024` | queue tests |
| 65 | WS-0024-BENCHMARK-CAPTURE | Hosted-safe raw delivery/reconciliation capture; no threshold inference | `worker-12/TASK-0024` | capture runner + tests |
| 67 | WS-0024-SUSTAINED-EVIDENCE | Enforce full measurement windows | `worker-11-fix/TASK-0024` | validator + dependent tests |
| 70 | WS-0024-RECOVERY-CERT | Recovery/reconciliation certification | `worker-7/TASK-0024` | recovery tests |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded telemetry certification | `worker-8/TASK-0024` | telemetry tests |
| 90 | WS-0024-SECURITY-CERT | Delivery security certification | `worker-9/TASK-0024` | security tests |
| 100 | WS-0024-FINAL-GATE | Complete evidence + approved threshold gate | `worker-10/TASK-0024` | final gate + tests |
| 105 | WS-0024-SOURCE-PINNING | Measured source/final-head separation | `worker-source-pin/TASK-0024` | gate/source-pinning files |
| 107 | WS-0024-BENCHMARK-ENV | Immutable hosted benchmark runtime | `worker-benchmark-env/TASK-0024` | benchmark runtime/runbook/tests |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate hosted capture, evidence intake and final closeout | `supervisor/task-0024-capture-attest-activate` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Merge the issue #157 hosted capture-attestation repair with fresh exact-head continuity/application/security checks.
2. Select and pin the resulting descendant merge commit as the exact benchmark source; redeploy the existing Railway runner to that exact source and verify SUCCESS.
3. Execute delivery and reconciliation capture on the same dedicated environment/source with two runs each and full 30-second measurement windows.
4. Validate both evidence documents and preserve exact fingerprints.
5. Present actual measured p95/p99/throughput results + fingerprints to the human Delivery owner for explicit numeric approval.
6. Commit approved evidence/manifest on a descendant final-certification branch, resolve canonical `TBD_MEASURED` only from that approval, and run the clean-checkout final certification gate plus every required exact-head CI/release/continuity check.
7. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025 activate.
