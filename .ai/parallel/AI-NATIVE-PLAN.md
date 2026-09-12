# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — source-pinning hardening**. TASK-0024 remains the canonical PHASE-04 certification task. Production-representative benchmark evidence and human Delivery-owner threshold approval are still required, but supervisor audit found an internal final-gate contradiction that must be removed before external evidence is accepted: benchmark evidence records the commit actually measured, while committed evidence/approval artifacts necessarily create a later final acceptance head. Requiring those SHAs to be identical is self-referential and cannot be satisfied correctly.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-source-pinning`  
Trusted baseline: `d0bc9580a501066df94157864e806ce2a2d5ddc0`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

TASK-0023 is complete, but PHASE-04 is not certified. Environment-sensitive queue-age, end-to-end latency, sustainable-throughput and reconciliation-lag thresholds remain `TBD_MEASURED`. Hosted-CI wall-clock duration is not production SLO evidence. No numeric threshold may be invented, and no AI agent may impersonate the Delivery owner.

The canonical distinction for closeout is now explicit:

- **benchmark source commit** — the exact code commit executed by the production-representative benchmark runner and recorded in each evidence document plus the owner-approved threshold manifest;
- **final acceptance head** — the later repository commit containing the reviewed evidence/threshold artifacts, resolved SLO contract and final closeout state, on which exact-head CI/certification runs.

The final acceptance head must preserve the benchmarked delivery behavior and derive from the benchmark source commit; the final gate must not require the committed artifact-containing head to equal the earlier measured source SHA.

Sustainable-throughput evidence remains hardened: every measured run must cover its declared `scenario.measurement_window_seconds`. An operation-limited short burst that finishes earlier is invalid evidence.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed `TBD_MEASURED` gates | `worker-1/TASK-0024` | `docs/operations/TASK-0024-CERTIFICATION.md` |
| 20 | WS-0024-BENCHMARK-EVIDENCE | Validate/aggregate repeated benchmark evidence without inferring thresholds | `worker-2/TASK-0024` | `tools/delivery_benchmark_evidence.py`, test |
| 30 | WS-0024-POSTGRES-CERT | PostgreSQL durability/contention/rollback/workspace certification | `worker-3/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04PostgresCertificationTest.php` |
| 40 | WS-0024-REDIS-CERT | Redis latency/interruption/lease recovery certification | `worker-4/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04RedisCertificationTest.php` |
| 50 | WS-0024-PROVIDER-CERT | Provider-neutral fault/failover certification | `worker-5/TASK-0024` | `tests/Integration/Providers/Phase04ProviderCertificationTest.php` |
| 60 | WS-0024-QUEUE-CERT | Queue/rate/quota/backpressure certification plus measured evidence | `worker-6/TASK-0024` | `tests/Integration/DeliveryEngine/Phase04QueueCertificationTest.php` |
| 65 | WS-0024-BENCHMARK-CAPTURE | Operator-safe raw queue/E2E/reconciliation benchmark capture; no threshold inference | `worker-12/TASK-0024` | `tools/task0024_benchmark_capture.php`, test |
| 67 | WS-0024-SUSTAINED-EVIDENCE | Reject measured runs shorter than their declared measurement window | `worker-11-fix/TASK-0024` | benchmark validator + dependent tests |
| 70 | WS-0024-RECOVERY-CERT | Retry/ambiguity/breaker/DLQ/reconciliation/duplicate certification | `worker-7/TASK-0024` | recovery certification test |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded hotspot/blocking telemetry and tenant isolation | `worker-8/TASK-0024` | telemetry certification test |
| 90 | WS-0024-SECURITY-CERT | Cross-workspace, redaction and policy-denial security certification | `worker-9/TASK-0024` | security certification test |
| 100 | WS-0024-FINAL-GATE | Deterministic complete-evidence + approved-threshold gate | `worker-10/TASK-0024` | certification gate + test |
| 105 | WS-0024-SOURCE-PINNING | Separate measured benchmark source SHA from the later artifact-containing final acceptance head | `worker-source-pin/TASK-0024` | certification contract, final gate, gate tests |
| 110 | WS-0024-CONTROL-ACTIVATION | Maintain registry/leases and final closeout sequencing | `supervisor/task-0024-source-pinning` | `.ai/parallel/*` control files |
<!-- WORKSTREAM_TABLE_END -->

## Source-pinning hardening rules

1. Benchmark evidence and the threshold manifest must all pin one exact benchmark source commit.
2. The gate may compare evidence/manifest source SHA to the declared benchmark source SHA, but must not call that SHA the final acceptance head.
3. The final gate executes from the final repository checkout and must report that final checkout head separately from the benchmark source commit.
4. The benchmark source commit must be an ancestor of the final acceptance head; missing history or an unrelated source commit is fail-closed.
5. The final acceptance head must contain the reviewed evidence/approval artifacts and resolved SLO contract and must pass exact-head application/integration/E2E/security/continuity/release checks.
6. Source-pinning hardening may not alter measured values, infer thresholds, weaken evidence validation, or add PHASE-05 capability.

## External evidence gate

After source-pinning hardening lands, TASK-0024 still cannot complete until one production-representative non-production environment supplies validated delivery and reconciliation evidence; every measured run covers its declared window; a human Delivery owner explicitly approves the numeric threshold set and exact evidence fingerprints; required `TBD_MEASURED` values are replaced from that approval; and the final gate plus all exact-head checks pass.

## Final closeout

Only after AC-1 through AC-7 pass on the final acceptance head may the Supervisor synchronize canonical task index/roadmap, current state, checkpoint and append-only journal transactionally and activate TASK-0025.
