# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — committed Railway v7 evidence under explicit human numeric-approval hold**. The production-representative delivery + reconciliation evidence pair is committed on main. TASK-0024 remains blocked until the human Delivery owner explicitly approves a specific numeric threshold set pinned to the exact benchmark source and evidence fingerprints. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-human-threshold-approval`  
Trusted repository baseline: `2311fd4921e0ae5e9e3e23ca5da035f0575cf96e`  
Benchmark source: `dab9b2002770c45fb9543b733d67e868bdb97d93`  
Railway archival deployment: `8e60ce6f-11a6-46e7-8e5b-a7a37933ba67`  
Delivery benchmark: `task0024-delivery-prodrep-07`  
Delivery fingerprint: `3d634f3da23ca069469ec554b0c72746f12d6cdae9fbec7fd72080cad5ff8041`  
Delivery raw SHA-256: `463ab4f95c38844f7e509af21db5a8ac8450bff770fd9f42af541b93d02fd837`  
Reconciliation benchmark: `task0024-reconciliation-prodrep-07`  
Reconciliation fingerprint: `01c8b45f929cf44d63926185820ec53977ddf325df740dbb746e3a75f878ba95`  
Reconciliation raw SHA-256: `fef980468614ab56261c1f3239be11b71789a34bf8a0bd28b92a8c45515f9e0d`  
Committed evidence merge: PR #172 → `2311fd4921e0ae5e9e3e23ca5da035f0575cf96e`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The user's earlier authorization covers provisioning and running the dedicated production-representative non-production Railway benchmark environment. It does **not** pre-approve any numeric SLO threshold. No AI agent may infer, round, or approve thresholds on the Delivery owner's behalf, fabricate approval metadata, use GitHub CI wall-clock duration as production SLO evidence, or activate PHASE-05 early.

## Verified measurement facts

The exact committed v7 evidence pair was independently reconstructed from the Railway archival payload, byte-hash checked, committed by PR #172, and revalidated with the repository's nearest-rank evidence semantics.

Delivery pooled measurements:
- queue age p95: `2115.5879497528076 ms`
- queue age p99: `2862.8649711608887 ms`
- end-to-end p95: `2242.6178455352783 ms`
- end-to-end p99: `2987.617015838623 ms`
- delivery throughput per run: `7.255834504860022`, `7.662130736362523 ops/s`
- delivery sustainable-throughput evidence minimum: `7.255834504860022 ops/s`
- delivery pooled throughput: `7.4588430193545845 ops/s`

Reconciliation pooled measurements:
- reconciliation lag p95: `52.111148834228516 ms`
- reconciliation lag p99: `64.10813331604004 ms`
- reconciliation throughput per run: `6.984616965285404`, `6.68274916854904 ops/s`
- reconciliation throughput minimum: `6.68274916854904 ops/s`
- reconciliation pooled throughput: `6.833622662890515 ops/s`

These are measured observations, **not approved thresholds**. Canonical `docs/operations/TASK-0023-DELIVERY-SLOS.md` remains `TBD_MEASURED` until explicit human approval.

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
| 108 | WS-0024-EVIDENCE-INTAKE | Commit byte-verified Railway v7 raw evidence | `worker-evidence-intake/TASK-0024` | two v7 evidence JSON files |
| 110 | WS-0024-CONTROL-ACTIVATION | Hold on explicit human numeric approval, then coordinate final closeout | `supervisor/task-0024-human-threshold-approval` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Exact next sequence

1. Merge this post-evidence control reconciliation with fresh exact-head continuity/application/security checks.
2. Present the exact measured values and both evidence fingerprints to the human Delivery owner.
3. Require explicit approval of **specific numeric values** for:
   - `queue_age_p95_ms`
   - `queue_age_p99_ms`
   - `end_to_end_p95_ms`
   - `end_to_end_p99_ms`
   - `sustainable_throughput_ops_s`
   - `reconciliation_lag_p95_ms`
   - `reconciliation_lag_p99_ms`
4. Only after that approval, register a bounded closeout worker lane that may create the approved threshold manifest, replace canonical `TBD_MEASURED` values with exactly the approved numbers, and pin `dab9b2002770c45fb9543b733d67e868bdb97d93` plus fingerprints `3d634f3da23ca069469ec554b0c72746f12d6cdae9fbec7fd72080cad5ff8041` and `01c8b45f929cf44d63926185820ec53977ddf325df740dbb746e3a75f878ba95`.
5. Run the clean-checkout TASK-0024 certification gate on a descendant acceptance head, requiring the benchmark source to be an ancestor and all committed evidence/manifest inputs to match Git.
6. Run all final exact-head backend/integration/architecture/static/format/frontend/E2E/security/release/continuity gates.
7. Only after AC-1 through AC-7 pass may TASK-0024 complete and TASK-0025/PHASE-05 activate.
