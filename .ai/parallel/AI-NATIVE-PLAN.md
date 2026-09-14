# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — Railway benchmark Python validator runtime repair under external-evidence hold**. TASK-0024 remains blocked until real validated delivery + reconciliation benchmark evidence and explicit human Delivery-owner approval of the measured numeric threshold set are committed and pass final certification. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-python-validator-activate`  
Trusted baseline: `591e7d347fa9f24160cb9fd8f608643d03a502aa`  
Failed measurement source: `591e7d347fa9f24160cb9fd8f608643d03a502aa` — **no evidence accepted**  
Next benchmark source: **pending Python-validator runtime fix merge; pin the resulting exact descendant**  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issues #134, #149 and #162  
Completion signal: `Work Done and Submitted`

The user's authorization covers the dedicated production-representative non-production Railway benchmark environment and capture. It does not pre-approve unknown numeric thresholds. No AI agent may invent evidence, infer or round thresholds, impersonate the Delivery owner, use GitHub CI wall-clock duration as SLO evidence, or activate PHASE-05 early.

The private Railway project `vsn-marketing-task0024-benchmark` has private PostgreSQL, Redis, and an internal benchmark runner with no public domain. Hosted source attestation is fixed and validated: PR #159 makes `--commit-sha`, `TASK0024_BENCHMARK_SOURCE_SHA`, and immutable `RAILWAY_GIT_COMMIT_SHA` fail closed unless they are full SHAs and exactly equal; PR #161 corrected hosted runbook instructions. Exact source `591e7d347fa9f24160cb9fd8f608643d03a502aa` deployed successfully and runtime printed that exact SHA.

The first real delivery benchmark attempt on Railway deployment `93429d82-2f22-4e97-b6ea-77bd95167f2a` produced a temporary raw document but correctly failed validation before publishing evidence because the benchmark image does not contain `python3`, which the capture tool requires to execute `tools/delivery_benchmark_evidence.py`. Error: `TASK-0024 benchmark capture blocked: emitted evidence failed validator: sh: 1: exec: python3: not found`. The temporary file is explicitly invalid and must not be committed, fingerprinted for approval, or used for certification. Reconciliation capture did not begin.

Issue #162 tracks the runtime repair. `WS-0024-BENCHMARK-ENV` is reactivated on fresh branch `worker-benchmark-env-python/TASK-0024` with write scope limited to the benchmark Dockerfile and its environment contract test. The repair must add the Python 3 runtime required by the repository validator and prove that dependency in tests without weakening any existing source/database/Redis safety contract.

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
| 107 | WS-0024-BENCHMARK-ENV | Python validator runtime in immutable benchmark image | `worker-benchmark-env-python/TASK-0024` | benchmark Dockerfile + environment contract test |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate runtime repair, fresh capture, evidence intake and final closeout | `supervisor/task-0024-python-validator-activate` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Merge this control transaction with fresh exact-head continuity/application/security checks.
2. On `worker-benchmark-env-python/TASK-0024`, add Python 3 to the immutable benchmark runtime and extend the environment contract test to require it; do not touch evidence or thresholds.
3. Merge the runtime repair with exact-head checks. Select the resulting descendant main commit as a fresh benchmark source and create a new immutable `benchmark/TASK-0024-<sha8>` ref.
4. Redeploy the existing private Railway runner to that exact source, with the same private PostgreSQL/Redis environment and exact source variable, and verify runtime SUCCESS.
5. Discard the failed attempt's temporary file and rerun delivery and reconciliation capture from scratch, two runs each, full 30-second measurement windows, same environment/source.
6. Require both published evidence documents to pass `tools/delivery_benchmark_evidence.py`; preserve exact fingerprints and raw observations.
7. Present actual p95/p99/throughput measurements and fingerprints to the human Delivery owner for explicit numeric approval.
8. Only from that approval, commit evidence and manifest on a descendant final-certification branch, resolve canonical `TBD_MEASURED`, and run the clean-checkout TASK-0024 certification gate plus every exact-head CI/release/continuity check.
9. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025/PHASE-05 activate.
