# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — hosted benchmark provisioning under external-evidence hold**. TASK-0024 remains blocked until real production-representative benchmark evidence and explicit human Delivery-owner approval of the resulting numeric threshold set are committed and pass final certification. PHASE-05 remains blocked.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-benchmark-env-landed`  
Trusted baseline: `ee90f43decfa1f3c8624ea66ed11303eedc05b65`  
Benchmark source candidate: `ee90f43decfa1f3c8624ea66ed11303eedc05b65`  
Broadcast channel: GitHub issue #43  
Benchmark tracking: GitHub issues #134 and #149  
Completion signal: `Work Done and Submitted`

The user's approval authorizes provisioning and running a dedicated production-representative **non-production** benchmark environment. It is not advance approval of numeric thresholds that have not yet been measured. GitHub-hosted CI wall-clock duration is not production SLO evidence, no threshold may be invented or inferred automatically, and no AI agent may impersonate the Delivery owner.

Repository-side benchmark prerequisites are now merged. PR #152 at `ee90f43decfa1f3c8624ea66ed11303eedc05b65` added the immutable source-containing PHP 8.5 benchmark runtime, fail-closed environment/source attestation, private PostgreSQL/Redis deployment runbook, explicit operator-triggered capture flow, and deterministic contract tests. Its fresh exact head passed AI Continuity Guard, Application Foundation CI, and Security Supply Chain CI before merge.

The current Railway integration path intentionally does not use legacy `railway.json` / `railway.toml` Config-as-Code because Railway deprecated that path for new services in 2026. Hosted setup follows the merged runbook using current service settings/plugin/CLI/API, private PostgreSQL + Redis, the custom benchmark Dockerfile, exact source pinning, and sealed environment variables.

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
| 105 | WS-0024-SOURCE-PINNING | Separate measured source SHA from later final acceptance head | `worker-source-pin/TASK-0024` | certification contract, final gate, gate tests |
| 107 | WS-0024-BENCHMARK-ENV | Immutable hosted benchmark runtime + deployment contract | `worker-benchmark-env/TASK-0024` | benchmark Docker/runbook/tests |
| 110 | WS-0024-CONTROL-ACTIVATION | Coordinate hosted provisioning, evidence intake and final closeout | `supervisor/task-0024-benchmark-env-landed` | Supervisor control files |
<!-- WORKSTREAM_TABLE_END -->

## Current external execution gate

1. Connect an authorized Railway account/integration and review the applicable plan/cost state before creating resources.
2. Provision one dedicated non-production project/environment with the benchmark runner plus private PostgreSQL and Redis.
3. Deploy exact source `ee90f43decfa1f3c8624ea66ed11303eedc05b65` unless a later prerequisite changes benchmark/application behavior.
4. Run the merged preflight and capture both delivery and reconciliation evidence with full measurement windows on the same environment/source.
5. Validate both evidence documents and preserve their exact fingerprints.
6. Present the actual p95/p99/throughput measurements and fingerprints to the human Delivery owner for explicit numeric approval.
7. Commit the approved evidence/manifest on a descendant final-certification branch and replace canonical `TBD_MEASURED` values only from that approval.
8. Run `tools/task0024_certification_gate.py` from a clean final checkout plus every required exact-head application, integration, E2E, security, release/integrity and continuity gate.
9. Only after AC-1 through AC-7 pass may the Supervisor complete TASK-0024 and activate TASK-0025.

No connected production-representative Railway environment has yet been established in this session. Do not fabricate evidence, create unreviewed paid resources, auto-approve numeric thresholds, or start PHASE-05.
