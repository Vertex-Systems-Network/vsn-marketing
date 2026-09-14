# AI-Native Parallel Plan — TASK-0024 PHASE-04 Delivery Certification

Status: **active — user-prioritized cyber-security hardening**. TASK-0024 remains the canonical PHASE-04 certification task. The registered source-pinning worker remains isolated on its existing branch, but merge sequencing is paused while the Supervisor closes concrete security findings discovered during the requested repository audit.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0024-security-audit`  
Trusted baseline: `a4fdfc523f9ed2d4c50411a927a87389cef491a9`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

TASK-0023 is complete, but PHASE-04 is not certified. Environment-sensitive queue-age, end-to-end latency, sustainable-throughput and reconciliation-lag thresholds remain `TBD_MEASURED`. Hosted-CI wall-clock duration is not production SLO evidence. No numeric threshold may be invented, and no AI agent may impersonate the Delivery owner.

## Security interrupt

The user explicitly requested a cyber-security audit before further feature/certification work. Supervisor review found four repository-side hardening gaps that are in-scope to fix without adding PHASE-05 capability:

1. the public session-login route has no explicit brute-force throttling;
2. `/api/runtime`, `/api/metrics`, and detailed `/api/health/ready` expose operational metadata without access control;
3. baseline browser response headers do not yet enforce MIME-sniffing, framing, referrer, permissions, and a minimal non-breaking CSP policy;
4. the production session cookie secure flag depends on an operator explicitly setting `SESSION_SECURE_COOKIE` rather than failing safe by default.

The existing security supply-chain workflow remains authoritative for immutable action pins, PHP SAST, dependency audits, repository secret scanning, container vulnerability/secret scanning, and reproducible source/SBOM evidence. The active main ruleset remains strict for exact-head required checks, force-push/deletion protection, and review-thread resolution under the repository's documented single-maintainer governance model.

The authorized security hardening is limited to authentication throttling, operational-endpoint access control and throttling, safe response headers, production-secure session-cookie defaults, an ADR, and regression/E2E updates needed to preserve the intended liveness/readiness split. No delivery threshold, measured evidence, provider behavior, or PHASE-05 product capability may change.

The source-pinning distinction remains canonical:

- **benchmark source commit** — the exact code commit executed by the production-representative benchmark runner and recorded in each evidence document plus the owner-approved threshold manifest;
- **final acceptance head** — the later repository commit containing the reviewed evidence/threshold artifacts, resolved SLO contract and final closeout state, on which exact-head CI/certification runs.

The final acceptance head must preserve the benchmarked delivery behavior and derive from the benchmark source commit. Sustainable-throughput evidence remains hardened: every measured run must cover its declared `scenario.measurement_window_seconds`.

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
| 110 | WS-0024-CONTROL-ACTIVATION | User-prioritized cyber audit, hardening, and control sequencing | `supervisor/task-0024-security-audit` | registered Supervisor security/control paths |
<!-- WORKSTREAM_TABLE_END -->

## Security hardening rules

1. Login throttling must use independent account and source-IP buckets so rotating either identifier alone does not bypass the other control.
2. Detailed operational endpoints must fail closed unless a high-entropy operations token is configured and presented; public liveness remains intentionally minimal.
3. Operations authentication compares secrets in constant time and never writes token values to logs or responses.
4. Operational endpoints are independently rate-limited and return `Cache-Control: no-store` when authorized.
5. Baseline response headers must be safe for the existing Inertia/Vite application and must not introduce broad unsafe CSP exemptions.
6. Production defaults to Secure session cookies while local HTTP development can explicitly opt out.
7. Every security behavior change requires deterministic tests; critical E2E smoke uses public liveness rather than exposing detailed readiness.
8. Security hardening must not alter delivery SLO values, benchmark evidence, or later-phase capability.

## Source-pinning hardening rules

1. Benchmark evidence and the threshold manifest must all pin one exact benchmark source commit.
2. The gate may compare evidence/manifest source SHA to the declared benchmark source SHA, but must not call that SHA the final acceptance head.
3. The final gate executes from the final repository checkout and must report that final checkout head separately from the benchmark source commit.
4. The benchmark source commit must be an ancestor of the final acceptance head; missing history or an unrelated source commit is fail-closed.
5. The final acceptance head must contain the reviewed evidence/approval artifacts and resolved SLO contract and must pass exact-head application/integration/E2E/security/continuity/release checks.
6. Source-pinning hardening may not alter measured values, infer thresholds, weaken evidence validation, or add PHASE-05 capability.

## External evidence gate

After security and source-pinning hardening land, TASK-0024 still cannot complete until one production-representative non-production environment supplies validated delivery and reconciliation evidence; every measured run covers its declared window; a human Delivery owner explicitly approves the numeric threshold set and exact evidence fingerprints; required `TBD_MEASURED` values are replaced from that approval; and the final gate plus all exact-head checks pass.

## Final closeout

Only after AC-1 through AC-7 pass on the final acceptance head may the Supervisor synchronize canonical task index/roadmap, current state, checkpoint and append-only journal transactionally and activate TASK-0025.
