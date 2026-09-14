# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — external-evidence hold**. TASK-0024 remains the canonical PHASE-04 certification task. All currently authorized repository implementation/hardening lanes are merged. PHASE-05 remains blocked until the external production-representative benchmark evidence and explicit human Delivery-owner threshold approval required by AC-4 are committed and pass the final exact-head certification gate.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-external-evidence-v2`  
Trusted baseline: `c9e4cc0822b10a66c936e49202e54a999acfd717`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

TASK-0023 is complete, but PHASE-04 is not certified. Environment-sensitive queue-age, end-to-end latency, sustainable-throughput and reconciliation-lag thresholds remain `TBD_MEASURED`. Hosted-CI wall-clock duration is not production SLO evidence. No numeric threshold may be invented, and no AI agent may impersonate the Delivery owner.

The user-prioritized cyber-security hardening is merged on main via PR #142. Login brute-force throttling, protected detailed operational endpoints, baseline browser security headers, production-secure session-cookie defaults, and regression/E2E coverage are now part of the trusted baseline.

The TASK-0024 source-pinning hardening is merged on main via PR #144. The certification contract and final gate now distinguish:

- **benchmark source commit** — the exact code commit executed by the production-representative benchmark runner and recorded in each evidence document plus the human Delivery-owner-approved threshold manifest;
- **final acceptance head** — the later repository commit containing the reviewed evidence/threshold artifacts, resolved canonical SLO contract and closeout state, on which final certification and exact-head CI run.

The final acceptance head must derive from the benchmark source commit. The gate verifies ancestry instead of impossible SHA equality, runs from a clean committed checkout, accepts only tracked committed evidence/threshold artifacts, and always uses the canonical TASK-0023 SLO contract. Sustainable-throughput evidence remains hardened: every measured run must cover its declared `scenario.measurement_window_seconds`.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Capability | Branch | Write scope |
|---:|---|---|---|---|
| 10 | WS-0024-CERT-CONTRACTS | AC/evidence map; preserve fail-closed `TBD_MEASURED` gates | `worker-1/TASK-0024` | `docs/operations/TASK-0024-CERTIFICATION.md` |
| 20 | WS-0024-BENCHMARK-EVIDENCE | Validate/aggregate repeated benchmark evidence without inferring thresholds | `worker-2/TASK-0024` | benchmark validator + test |
| 30 | WS-0024-POSTGRES-CERT | PostgreSQL durability/contention/rollback/workspace certification | `worker-3/TASK-0024` | PostgreSQL certification test |
| 40 | WS-0024-REDIS-CERT | Redis latency/interruption/lease recovery certification | `worker-4/TASK-0024` | Redis certification test |
| 50 | WS-0024-PROVIDER-CERT | Provider-neutral fault/failover certification | `worker-5/TASK-0024` | provider certification test |
| 60 | WS-0024-QUEUE-CERT | Queue/rate/quota/backpressure certification plus measured evidence | `worker-6/TASK-0024` | queue certification test |
| 65 | WS-0024-BENCHMARK-CAPTURE | Operator-safe raw queue/E2E/reconciliation benchmark capture; no threshold inference | `worker-12/TASK-0024` | capture runner + test |
| 67 | WS-0024-SUSTAINED-EVIDENCE | Reject measured runs shorter than their declared measurement window | `worker-11-fix/TASK-0024` | benchmark validator + dependent tests |
| 70 | WS-0024-RECOVERY-CERT | Retry/ambiguity/breaker/DLQ/reconciliation/duplicate certification | `worker-7/TASK-0024` | recovery certification test |
| 80 | WS-0024-OBSERVABILITY-CERT | Bounded hotspot/blocking telemetry and tenant isolation | `worker-8/TASK-0024` | telemetry certification test |
| 90 | WS-0024-SECURITY-CERT | Cross-workspace, redaction and policy-denial security certification | `worker-9/TASK-0024` | security certification test |
| 100 | WS-0024-FINAL-GATE | Deterministic complete-evidence + approved-threshold gate | `worker-10/TASK-0024` | certification gate + test |
| 105 | WS-0024-SOURCE-PINNING | Separate measured benchmark source SHA from the later artifact-containing final acceptance head | `worker-source-pin/TASK-0024` | certification contract, final gate, gate tests |
| 110 | WS-0024-CONTROL-ACTIVATION | Maintain fail-closed external-evidence hold and final closeout sequencing | `supervisor/task-0024-external-evidence-v2` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## External evidence gate

TASK-0024 remains blocked until all of the following are supplied from one dedicated production-representative non-production environment:

1. **Delivery evidence** containing raw `queue_age_ms`, raw `end_to_end_ms`, and sustained throughput observations.
2. **Reconciliation evidence** containing raw `reconciliation_lag_ms` and applicable sustained throughput observations.
3. Both evidence documents pin the same exact benchmark source commit and the same environment identity, deterministic workload assumptions, and required repeated measurement runs.
4. Every measured run covers the full declared `measurement_window_seconds`; short fixed-operation bursts are invalid for sustainable-throughput certification.
5. Both evidence documents pass `tools/delivery_benchmark_evidence.py` without inferred thresholds or generated approval.
6. A real human Delivery owner reviews those measurements and explicitly approves a numeric threshold manifest that pins the same benchmark source commit and exact evidence fingerprints, with role `delivery_owner`, real `approved_by`, and real `approved_at` values.
7. The approved evidence JSON files and threshold manifest are committed to a later descendant final-certification branch/head.
8. Canonical `docs/operations/TASK-0023-DELIVERY-SLOS.md` `TBD_MEASURED` values are replaced only from that human-approved threshold set.
9. From a clean final checkout, `tools/task0024_certification_gate.py` is invoked with `--source-commit <BENCHMARK_SOURCE_SHA>` and only committed repository evidence/threshold paths; it resolves the final acceptance HEAD itself and verifies source ancestry.
10. All applicable exact-head application, integration, architecture, static, format, frontend, E2E, security, release/integrity, and AI-continuity checks pass on the same final acceptance head.

No connected production-representative benchmark environment has been identified from this session, so the Supervisor must not fabricate benchmark evidence, create paid/production infrastructure without authorization, invent numeric thresholds, or auto-fill Delivery-owner approval.

## Final closeout

Only after AC-1 through AC-7 pass on the final acceptance head may the Supervisor synchronize canonical task index/roadmap, current state, checkpoint and append-only journal transactionally and activate TASK-0025. Until then TASK-0024 remains open and PHASE-05 is blocked.
