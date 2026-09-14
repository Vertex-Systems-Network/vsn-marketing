# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — exact Railway v7 numeric threshold set explicitly approved by the human Delivery owner; final certification workflow repair in progress**. The committed production-representative delivery + reconciliation evidence pair remains pinned to benchmark source `dab9b2002770c45fb9543b733d67e868bdb97d93`. PHASE-05 remains blocked until final certification and all exact-head gates pass.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-cert-workflow-fix`  
Trusted repository baseline: `bc9fdf8f97b15b1d1fccb80c61dde2ba814ff422`  
Benchmark source: `dab9b2002770c45fb9543b733d67e868bdb97d93`  
Railway archival deployment: `8e60ce6f-11a6-46e7-8e5b-a7a37933ba67`  
Delivery benchmark: `task0024-delivery-prodrep-07`  
Delivery fingerprint: `3d634f3da23ca069469ec554b0c72746f12d6cdae9fbec7fd72080cad5ff8041`  
Reconciliation benchmark: `task0024-reconciliation-prodrep-07`  
Reconciliation fingerprint: `01c8b45f929cf44d63926185820ec53977ddf325df740dbb746e3a75f878ba95`  
Human approval identity: `wpessential`  
Human approval time: `2026-09-15T02:20:28+05:00`  
Human approval text: `Approve TASK-0024 exact v7 thresholds`  
Threshold closeout PR: #176  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The approval is limited to the exact seven v7 numeric values below and the two exact evidence fingerprints above. No AI agent may round, loosen, tighten, substitute, infer, or otherwise alter these numbers.

## Human-approved numeric threshold set

- `queue_age_p95_ms` max: `2115.5879497528076`
- `queue_age_p99_ms` max: `2862.8649711608887`
- `end_to_end_p95_ms` max: `2242.6178455352783`
- `end_to_end_p99_ms` max: `2987.617015838623`
- `sustainable_throughput_ops_s` min: `7.255834504860022`
- `reconciliation_lag_p95_ms` max: `52.111148834228516`
- `reconciliation_lag_p99_ms` max: `64.10813331604004`

## Final-certification workflow repair

PR #176 first executed the Supervisor-owned `TASK-0024 Final Certification` workflow against the approved worker artifacts. The unchanged certification gate correctly failed closed with `final certification gate requires a clean committed checkout`. The cause is workflow execution, not benchmark evidence or thresholds: importing the Python validator can create `tools/__pycache__` before `_require_clean_checkout()` inspects `git status`. The repair is therefore limited to invoking the unchanged gate with Python bytecode generation disabled (`python3 -B` / equivalent). No gate logic, evidence, manifest value, SLO number, source SHA, or approval metadata may change.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed measured gates | `worker-1/TASK-0024` | certification contract |
| 20 | WS-0024-BENCHMARK-EVIDENCE | Validate repeated benchmark evidence without inferring thresholds | `worker-2/TASK-0024` | evidence validator + tests |
| 30 | WS-0024-POSTGRES-CERT | PostgreSQL certification | `worker-3/TASK-0024` | PostgreSQL tests |
| 40 | WS-0024-REDIS-CERT | Redis certification | `worker-4/TASK-0024` | Redis tests |
| 50 | WS-0024-PROVIDER-CERT | Provider-neutral certification | `worker-5/TASK-0024` | provider tests |
| 60 | WS-0024-QUEUE-CERT | Queue/rate/quota/backpressure certification | `worker-6/TASK-0024` | queue tests |
| 65 | WS-0024-BENCHMARK-CAPTURE | Benchmark-only reconciliation breaker isolation; hosted fail-closed capture | `worker-benchmark-reconciliation-isolation/TASK-0024` | capture runner + tests |
| 67 | WS-0024-SUSTAINED-EVIDENCE | Enforce full measurement windows | `worker-11-fix/TASK-0024` | validator + dependent tests |
| 70 | WS-0024-RECOVERY-CERT | Recovery/reconciliation certification | `worker-7/TASK-0024` | recovery tests |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded telemetry certification | `worker-8/TASK-0024` | telemetry tests |
| 90 | WS-0024-SECURITY-CERT | Delivery security certification | `worker-9/TASK-0024` | security tests |
| 100 | WS-0024-FINAL-GATE | Complete evidence + approved threshold gate | `worker-10/TASK-0024` | final gate + tests |
| 105 | WS-0024-SOURCE-PINNING | Measured source/final-head separation | `worker-source-pin/TASK-0024` | gate/source-pinning files |
| 107 | WS-0024-BENCHMARK-ENV | Python validator runtime in immutable benchmark image | `worker-benchmark-env-python/TASK-0024` | benchmark Dockerfile + environment contract test |
| 108 | WS-0024-EVIDENCE-INTAKE | Commit byte-verified Railway v7 raw evidence | `worker-evidence-intake/TASK-0024` | two v7 evidence JSON files |
| 109 | WS-0024-THRESHOLD-CLOSEOUT | Commit exact approved manifest + resolve canonical measured SLO values | `worker-threshold-closeout/TASK-0024` | v7 threshold manifest + TASK-0023 SLO contract |
| 110 | WS-0024-CONTROL-ACTIVATION | Own/repair task-specific certification workflow and coordinate final closeout | `supervisor/task-0024-cert-workflow-fix` | Supervisor control files + task-specific workflow |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Change only `.github/workflows/task0024-final-certification.yml` execution to suppress Python bytecode generation while preserving the unchanged certification CLI inputs and full Git history.
2. Merge this Supervisor repair only after exact-head continuity/application/security checks and review-thread audit.
3. Synchronize open threshold-closeout PR #176 with the resulting main without altering its approved manifest or SLO content.
4. Require `TASK-0024 Final Certification` to pass on the synchronized PR exact head, together with AI Continuity, Application Foundation, Security Supply Chain and zero unresolved review threads.
5. Squash merge the worker closeout, then require the task-specific certification push run plus all final exact-head release/continuity/application/security checks on the resulting main.
6. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025/PHASE-05 activate.
