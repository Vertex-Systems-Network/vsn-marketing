# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — Railway benchmark runbook correction under external-evidence hold**. TASK-0024 remains blocked until real delivery + reconciliation benchmark evidence and explicit human Delivery-owner approval of the measured numeric threshold set are committed and pass final certification. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-runbook-fix-activate`  
Trusted baseline: `7773bc98edc68ee5890e564bc99eb26faa3bc1e4`  
Current Railway runtime source: `446abc364bec7c3aea3307d8d5eafa9faa8b60cf` (superseded for measurement; do not capture yet)  
Next benchmark source: **pending runbook correction merge; pin that exact descendant commit before capture**  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issues #134, #149 and #157  
Completion signal: `Work Done and Submitted`

The user's authorization covers a dedicated production-representative non-production benchmark environment and capture. It does not pre-approve unknown numeric thresholds. No AI agent may invent evidence, infer/round thresholds, impersonate the Delivery owner, use GitHub CI wall-clock as SLO evidence, or activate PHASE-05 early.

The private Railway project `vsn-marketing-task0024-benchmark` is provisioned with private PostgreSQL, Redis, and an internal benchmark runner. PR #155 fixed hosted runtime source attestation without weakening local Git HEAD verification, and the real Railway deployment `2bc5f4cc-839f-4505-8b25-fec3a4174eb8` reached SUCCESS at source `446abc364bec7c3aea3307d8d5eafa9faa8b60cf`. PostgreSQL/Redis are SUCCESS and normal forward migrations completed.

PR #159 merged as `7773bc98edc68ee5890e564bc99eb26faa3bc1e4` after exact-head continuity/application/security success. The capture tool now performs source attestation before application bootstrap; on Railway it requires explicit `--commit-sha`, `TASK0024_BENCHMARK_SOURCE_SHA`, and immutable `RAILWAY_GIT_COMMIT_SHA` to be full SHAs and exactly equal, while local/non-Railway capture retains real Git HEAD equality. The capture-tool repair itself generated no benchmark evidence, thresholds, approvals, or PHASE-05 capability.

One documentation-only mismatch remains before measurement: `docs/operations/TASK-0024-BENCHMARK-ENV.md` still tells a Railway operator to resolve `SOURCE_SHA` with `git rev-parse HEAD`, but Railway's Docker source archive intentionally omits `.git`. `WS-0024-BENCHMARK-CAPTURE` is therefore complete and its lease is released. `WS-0024-BENCHMARK-ENV` is reactivated on a fresh current-main descendant branch with a **docs-only** lease to correct hosted preflight/capture commands to use Railway immutable commit metadata. Runtime code is not authorized in this lane.

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
| 107 | WS-0024-BENCHMARK-ENV | Hosted runbook correction after verified runtime/capture attestation fixes | `worker-benchmark-env-docs/TASK-0024` | `docs/operations/TASK-0024-BENCHMARK-ENV.md` only |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate runbook correction, exact-source redeploy, evidence intake and final closeout | `supervisor/task-0024-runbook-fix-activate` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Merge this control transaction with fresh exact-head continuity/application/security checks.
2. On `worker-benchmark-env-docs/TASK-0024`, correct only the Railway runbook: hosted preflight/capture must use `SOURCE_SHA="$RAILWAY_GIT_COMMIT_SHA"` and explicitly require equality with `TASK0024_BENCHMARK_SOURCE_SHA`; local/non-Railway instructions may continue to use `git rev-parse HEAD`.
3. Merge that docs-only workstream with exact-head checks. Select the resulting descendant main commit as the benchmark source, create an immutable `benchmark/TASK-0024-<sha8>` branch, redeploy the existing private Railway runner to that exact source, set `TASK0024_BENCHMARK_SOURCE_SHA` to the same SHA, and verify Railway reports SUCCESS.
4. Execute delivery and reconciliation capture on the same dedicated environment/source with two runs each and full 30-second measurement windows.
5. Validate both evidence documents and preserve exact fingerprints.
6. Present actual measured p95/p99/throughput results + fingerprints to the human Delivery owner for explicit numeric approval.
7. Commit approved evidence/manifest on a descendant final-certification branch, resolve canonical `TBD_MEASURED` only from that approval, and run the clean-checkout final certification gate plus every required exact-head CI/release/continuity check.
8. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025 activate.

No benchmark capture is authorized until the docs-only runbook correction is merged and the runner is redeployed to the final exact selected benchmark source.
