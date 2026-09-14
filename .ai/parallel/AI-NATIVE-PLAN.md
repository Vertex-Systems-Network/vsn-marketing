# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — benchmark-environment activation under external-evidence hold**. TASK-0024 remains the canonical PHASE-04 certification task. The user has explicitly authorized proceeding with a dedicated production-representative non-production benchmark environment and capture flow. PHASE-05 remains blocked until real benchmark evidence and explicit human Delivery-owner approval of the resulting numeric threshold set are committed and pass the final exact-head certification gate.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-benchmark-env-activate`  
Trusted baseline: `53b1513d5193968311bc372ab665dcb006c8e3a7`  
Benchmark source candidate: `53b1513d5193968311bc372ab665dcb006c8e3a7`  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issue #149  
Completion signal: `Work Done and Submitted`

TASK-0023 is complete, but PHASE-04 is not certified. Environment-sensitive queue-age, end-to-end latency, sustainable-throughput and reconciliation-lag thresholds remain `TBD_MEASURED`. Hosted-CI wall-clock duration is not production SLO evidence. No numeric threshold may be invented, and no AI agent may impersonate the Delivery owner.

The user-prioritized cyber-security hardening is merged on main via PR #142. Login brute-force throttling, protected detailed operational endpoints, baseline browser security headers, production-secure session-cookie defaults, and regression/E2E coverage are now part of the trusted baseline.

The TASK-0024 source-pinning hardening is merged on main via PR #144. The certification contract and final gate now distinguish:

- **benchmark source commit** — the exact code commit executed by the production-representative benchmark runner and recorded in each evidence document plus the human Delivery-owner-approved threshold manifest;
- **final acceptance head** — the later repository commit containing the reviewed evidence/threshold artifacts, resolved canonical SLO contract and closeout state, on which final certification and exact-head CI run.

The final acceptance head must derive from the benchmark source commit. The gate verifies ancestry instead of impossible SHA equality, runs from a clean committed checkout, accepts only tracked committed evidence/threshold artifacts, and always uses the canonical TASK-0023 SLO contract. Sustainable-throughput evidence remains hardened: every measured run must cover its declared `scenario.measurement_window_seconds`.

Canonical ledger reconciliation is merged on main via PR #146. TASK-0024 task/index/state, BLOCKERS, LAST-CHECKPOINT and append-only journal event #57 consistently record the AC-4 external-evidence block. PR #148 then parked the Supervisor on the external-evidence hold. The user's subsequent approval authorizes benchmark-environment provisioning/capture work but does **not** pre-approve numeric thresholds that have not yet been measured.

Repository/runtime inspection confirmed the existing `docker/app/Dockerfile` and `compose.yaml` are developer-oriented: the Dockerfile does not copy repository source into the image and Compose relies on bind-mounted source. They must not be represented as an immutable production-representative hosted benchmark deployment without a dedicated benchmark runtime. `WS-0024-BENCHMARK-ENV` therefore owns only the isolated benchmark deployment image/config/runbook and its tests. It must not generate evidence, infer thresholds, or modify canonical SLO values.

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
| 107 | WS-0024-BENCHMARK-ENV | Build an immutable, source-containing production-style benchmark runtime and hosted deployment contract for dedicated PostgreSQL + Redis capture without generating evidence or thresholds | `worker-benchmark-env/TASK-0024` | benchmark Docker/config/runbook/tests |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate benchmark-environment activation, evidence intake and final closeout while preserving the fail-closed TASK-0024 gate | `supervisor/task-0024-benchmark-env-activate` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Benchmark-environment activation

The user has authorized proceeding with a dedicated production-representative non-production benchmark environment. Railway is the currently selected hosted path because it can colocate an application service, PostgreSQL and Redis on one private project network, but the Railway plugin is not yet installed/connected in this session. No paid resource may be created until the connected provider presents an applicable plan/cost state that is covered by user authorization.

`WS-0024-BENCHMARK-ENV` must:

1. build an immutable PHP runtime that copies the exact repository source into the image and installs locked Composer dependencies;
2. preserve the PHP/PostgreSQL/Redis runtime contracts used by TASK-0024 capture, including `pcntl`, `pdo_pgsql` and `phpredis`;
3. provide a provider-neutral benchmark entrypoint/config that fails closed unless `APP_ENV=benchmark` and a clearly benchmark/perf/load/staging/test database is configured;
4. use environment-provided secrets and private service connectivity; no credential may be committed;
5. document Railway mapping separately from the generic runtime, including private PostgreSQL/Redis variable references and the custom benchmark Dockerfile path;
6. avoid running the benchmark automatically at deploy time; an operator-triggered capture must use the exact source SHA and explicit safety acknowledgement;
7. add deterministic repository tests that inspect the benchmark runtime/config contract without contacting external infrastructure;
8. leave evidence JSON, approved threshold manifests, canonical `TBD_MEASURED` values and PHASE-05 code outside this workstream.

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

## Final closeout

Only after AC-1 through AC-7 pass on the final acceptance head may the Supervisor synchronize canonical task index/roadmap, current state, checkpoint and append-only journal transactionally and activate TASK-0025. Until then TASK-0024 remains blocked and PHASE-05 is blocked.
