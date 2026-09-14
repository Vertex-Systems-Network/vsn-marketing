# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — verified Railway v7 evidence intake under fail-closed numeric-approval hold**. TASK-0024 remains blocked until the verified raw evidence pair is committed and the human Delivery owner explicitly approves the numeric threshold set pinned to those exact fingerprints. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-evidence-intake-activate`  
Trusted baseline: `dab9b2002770c45fb9543b733d67e868bdb97d93`  
Benchmark source: `dab9b2002770c45fb9543b733d67e868bdb97d93`  
Railway archival deployment: `8e60ce6f-11a6-46e7-8e5b-a7a37933ba67`  
Delivery benchmark: `task0024-delivery-prodrep-07`  
Delivery fingerprint: `3d634f3da23ca069469ec554b0c72746f12d6cdae9fbec7fd72080cad5ff8041`  
Delivery raw SHA-256: `463ab4f95c38844f7e509af21db5a8ac8450bff770fd9f42af541b93d02fd837`  
Reconciliation benchmark: `task0024-reconciliation-prodrep-07`  
Reconciliation fingerprint: `01c8b45f929cf44d63926185820ec53977ddf325df740dbb746e3a75f878ba95`  
Reconciliation raw SHA-256: `fef980468614ab56261c1f3239be11b71789a34bf8a0bd28b92a8c45515f9e0d`  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issues #134, #149 and #168  
Completion signal: `Work Done and Submitted`

The user's authorization covers the dedicated production-representative non-production Railway benchmark environment and capture. It does not pre-approve numeric thresholds. No AI agent may invent evidence, infer or round thresholds, impersonate the Delivery owner, use GitHub CI wall-clock duration as SLO evidence, or activate PHASE-05 early.

PR #170 merged the benchmark-only reconciliation breaker isolation without changing production breaker defaults. Fresh Railway archival deployment `8e60ce6f-11a6-46e7-8e5b-a7a37933ba67` then ran exact source `dab9b2002770c45fb9543b733d67e868bdb97d93` on the existing dedicated private PostgreSQL/Redis benchmark environment. Both delivery and reconciliation evidence documents passed `tools/delivery_benchmark_evidence.py`, both declared full repeated measurement windows, and the runtime emitted the raw files as gzip+base64 archival payloads after validation.

The archival payloads were independently reconstructed outside Railway. Their byte-level SHA-256 values exactly match the hashes emitted by the deployment, and recomputing the validator's canonical JSON fingerprint algorithm exactly reproduces both published evidence fingerprints. `WS-0024-EVIDENCE-INTAKE` is therefore registered with write scope limited to the two verified raw JSON evidence files. It may not create a threshold manifest, change `TASK-0023-DELIVERY-SLOS.md`, generate approval metadata, change runtime code, or alter TASK/PHASE completion state.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed `TBD_MEASURED` gates | `worker-1/TASK-0024` | certification contract |
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
| 108 | WS-0024-EVIDENCE-INTAKE | Commit byte-verified Railway v7 raw evidence only | `worker-evidence-intake/TASK-0024` | two v7 raw evidence JSON files |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate verified evidence intake, human numeric approval and final closeout | `supervisor/task-0024-evidence-intake-activate` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Merge this control transaction with fresh exact-head continuity/application/security checks.
2. Create `worker-evidence-intake/TASK-0024` from that merged main and commit only `docs/operations/evidence/TASK-0024-delivery-prodrep-07.json` and `docs/operations/evidence/TASK-0024-reconciliation-prodrep-07.json`.
3. Re-run the repository evidence validator against both committed files and verify their raw SHA-256 values and canonical fingerprints still exactly match the Railway v7 archival deployment.
4. Merge the evidence-only PR after exact-head required checks and review-thread audit.
5. Present the exact measured queue-age p95/p99, end-to-end p95/p99, reconciliation-lag p95/p99, sustainable-throughput measurements and both evidence fingerprints to the human Delivery owner. Obtain explicit approval of specific numeric threshold values; do not infer or round them on the owner's behalf.
6. Only from that explicit approval, activate a bounded closeout lane to commit the approved threshold manifest, resolve canonical `TBD_MEASURED`, and pin the approved evidence revisions.
7. Run the clean-checkout TASK-0024 certification gate with source `dab9b2002770c45fb9543b733d67e868bdb97d93`, then every exact-head backend/integration/E2E/security/release/continuity gate.
8. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025/PHASE-05 activate.
