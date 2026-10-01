# PHASE-10 evidence and certification report

Status: TASK-0054 through TASK-0060 accepted. TASK-0061 evidence implemented; final exact-head and resulting-main full gates pending. Phase progress90%, overall70.20%. PHASE-11 remains planned/inactive.

Certification scope: the registered, provider-neutral **offline AI architecture**, as explicitly permitted by TASK-0054 research. Live activation remains denied. A completed phase in this scope does not certify a hosted model, production provider account, deployment or autonomous marketing execution.

## Acceptance, source and test matrix

| Task | Source | Deterministic verification | Accepted carrier / resulting main |
|---|---|---|---|
|0054 research | TASK-0054-RESEARCH.md; task/phase/registries | Current official provider/security sources, capability/privacy differences, route rubric, research/control validators | PR452 head2535b645b3b5b7756b253b37737dc88d3a0a3fea; main ce87aca5a4950e65463da91d66a60b052b7b4df1. Full exact-head/main evidence in journal261. |
|0055 gateway | AiGateway, AiRoutePolicy, DatabaseAiBudgetLedger/CircuitBreaker/TelemetryRecorder | Route region/classification/schema/tool/risk filtering, compatible fallback, atomic accounting, terminal statuses, contention | PR453/454; head90453cbb09b51332074769072872aa75775040f4; main6164490d180e2c83da2983fdd093d0e3ed8220cf. Main Application36846852912, Continuity36846852856, Security36846852858, Release36846852997, Scorecard36846852904 pass. |
|0056 context | AiContextAssembler, source/sanitizer policies, scoped DB repository | Workspace/brand/customer/run scope, provenance/revision/freshness/deletion, per-source permission, quarantined context, privacy denial and PostgreSQL isolation | PR455 head9bd36a7d77da4189577742409e257d626f5ab4a4; mainabe7df21c195c3d5843555f70c5c1c6f16bd5850. Main Application36905520775, Continuity36905520282, Security36905520201, Release36905520354, Scorecard36905520565 pass. |
|0057 structured tools | AiSchemaValidator, AiStructuredOutputValidator, scoped validator, AiTypedToolExecutor | Complete/schema/scope/known-reference validation; registered tool, typed args/results, risk, independent permissions/review, durable replay and reversible rollback | PR456 + repair457; accepted repaired main2fb294fdd4f9bce718fb436aa5b1b8901426b285; TASK-0057-CERTIFICATION.md contains exact-head/main jobs and the failed prior main. |
|0058 specialists | AiAgentCatalog/Runtime/ProposalGateway, output policy, immutable prompts/evals, computed report and promotion gate |12 specialists;48 v1 golden policy cases; separate contexts, finite plan/retries, ceiling, independent promotion/rollback and live-mode denial | PR458/main7262dce7c5118cd08a0874c016d8d60894273183; TASK-0058-CERTIFICATION.md. |
|0059 creative | Creative catalog/provider/generator/output/review gates, immutable text/image policies | Independent input rights, portable draft protocol, scoped references, bounded media, disclosure, exact candidate review; truthful rejection/accounting/circuit | PR459/mainb7ced3305224d011546e4938d4e96e9f2f3580c8; TASK-0059-CERTIFICATION.md. |
|0060 red-team |22-case corpus, AiRedTeamTest, AiRuntimeHarness; strict eval expectations | Direct/indirect payloads, retrieval poisoning, prompt canary, hallucinated references, foreign scope, tool/exfiltration/self-modification, denied budgets/loops, four unsafe outage fallbacks | PR460/mainc2653b56f96c56cd9fc6973cf5c2a6a41b964b20; TASK-0060-CERTIFICATION.md. |
|0061 certification | AiOfflineCertificationTest; ai_offline_certification.php; OFFLINE-MEASUREMENTS.v1.json | Independently reviewed exact v2 canary/v1 rollback selection, mode/scope denials, source-bound raw measurements and complete phase source/test/run evidence | Final certification exact-head/main gates pending. |

These are generic specialist proposal envelopes. Audience/journey/connector recommendations are not executable ASTs, graphs or connector programs. No new AI user interface, API endpoint, live provider adapter or permissive authority binding is installed.

## Golden policy and offline version rehearsal

All12 canonical specialists retain immutable v1 artifacts. Strategy has an additional immutable v2 candidate and matching four-case dataset. All52 pinned cases exercise positive grounded/no-evidence and negative invented-reference/foreign-workspace policy outcomes. This is schema/reference/authority behavior, not reviewer-rated marketing utility or model accuracy.

The rehearsal evaluates strategy v2, denies missing review, records an exact candidate/report-bound independent synthetic human grant, selects v2 through the actual runtime, then separately evaluates/reviews/selects prior v1 as rollback. Foreign-workspace transfer and live/production modes deny. These grants are test fixtures, not actual human approval for a production release. All prompt aliases remain null/candidate. No production canary, alias promotion, provider activation or deployment occurred.

## Measured local timing and accounting

Raw evidence: [OFFLINE-MEASUREMENTS.v1.json](OFFLINE-MEASUREMENTS.v1.json). Reproduce with `php tools/ai_offline_certification.php`; verify with the focused test/full suite. Capture uses PHP8.3.6 onLinux x86_64,40 recorded samples (20 v1 +20 v2), one unrecorded warm-up per version and fresh synthetic ports for each sample. The timed interval is one-step context/catalog/runtime/gateway invocation; harness construction is excluded.71 source hashes bind gateway/domain/contracts/registries/prompts/evals/harness/capture script/Composer lock.

Nearest-rank p50 **0.331453ms**; p95 **0.839499ms**. Each sample invokes one synthetic provider, reserves10 fixture units, settles2 units, and returns fixture usage3 input/4 output tokens. Total settled80 fixture units. These units are **not currency**, and tokens are fixture values. Timing measures this warm local no-network path. It does not establish real-provider cost/latency, production p50/p95, throughput, capacity, semantic quality or a numeric production SLO. No latency pass threshold is invented. Future measurements may differ; committed raw sample summaries are recomputed by the integrity test.

## Privacy, provider, fallback and observability boundaries

Authenticated server context determines workspace/brand/customer/run scope; retrieval source identity/provenance/permissions/freshness and text bounds are independently checked. Raw secret/contact patterns deny. Runtime memory is rebuilt per specialist. Scoped/hash/reference validation does not guarantee unrestricted semantic truth, full PII detection or every encoded disclosure.

Only explicitly configured registered routes/adapters can invoke. Offline specialist/creative paths require the offline marker; application defaults fail closed. Fallback preserves workspace, region/classification, schema, capabilities, tools, risk and total ceiling. Unknown outage cost stays reserved; invalid output is withheld but known consumption is accounted. Independent typed tools remain separate from proposal generation.

Runtime receipts record exact prompt/hash/context, route/result usage and measured local latency. Durable gateway DB traces record workspace/attempt/route/prompt/context references and final usage/cost/status; no raw sensitive prompt/output is persisted by this recorder. This is a bounded trace subset, not certification of a complete production observability/retention system.

Creative review is independently rights/brand/safety bound and never grants publication. PNG/JPEG MIME/header checks are not complete media decoding or safety screening. Content credentials remain unverified; hashes/references are not C2PA validation or commercial rights evidence. Video is unsupported and fails closed.

## Test and CI provenance

Local TASK-0060 accepted baseline:42 AI tests/544 assertions,774 backend tests/4663 assertions with136 local infrastructure skips. PR460 logs separately verify180 PostgreSQL/Redis integration tests/1137 assertions,4 architecture tests/2718 assertions,35 frontend tests and7 browser smoke tests. Suites overlap and are not summed into a misleading combined total.

TASK-0061 local and final certification workflow/job IDs are recorded below after observation. Required gates remain full Application Foundation (backend, architecture, static/format, frontend typecheck/unit/build, PostgreSQL/Redis integration, PHP8.3 floor, browser smoke), AI Continuity, Security Supply Chain and resulting-main Release Integrity/Scorecard. No gate or assertion was removed or weakened.

Failure history matters: PR456's first resulting main failed PostgreSQL integration due to inherited PDO/libpq connection and non-terminating forked child on exception. PR457 repaired pre-fork connection isolation and guaranteed child termination/reaping. Acceptance uses the repaired green main, not the failed run. Creative invalid-output work corrected premature success telemetry; red-team work corrected unknown eval expectation certification. Detailed evidence remains in the task certification packs.

CURRENT-STATE quality strings are historical snapshots; the latest TEST-STATE, task certificates, journal and exact run/job rows are the applicable certification basis.

## Live activation gates that remain unapproved

- Account-specific credential references, region/retention/data-use entitlements and independent workspace privacy approval.
- Concrete provider adapter/API/model version contracts, reviewer-rated task quality, real-model injection/leakage evaluations, representative repeated reliability/refusal/fallback runs.
- Dated account/model prices, actual usage and representative latency/capacity measurements, approved numeric production spend/rate/SLO controls.
- Actual rights/media ingestion/decoding/security/safety/C2PA verification where needed, independent production promotion/canary/rollback and observability/retention controls.
- Deployment/release, production publication/send or other external authority.

These are explicit live activation prerequisites from the original research, not completed live evidence. PHASE-11 is not activated by this certification. No successor task will be silently invented.

### TASK-0061 local verification

44 AI tests/1,027 assertions pass with fail-on-notice/warning. Full backend776 tests/5,146 assertions pass with136 local infrastructure skips.4 architecture tests/2,718 assertions pass separately. PHPStan, locked Pint, artifact pinning/history/negative tests, context/transaction/journal/append-only/parallel/supervisor/Runner validation pass. New exact-head and resulting-main full CI remain pending.
