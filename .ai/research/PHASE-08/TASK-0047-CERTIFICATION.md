+# TASK-0047 — PHASE-08 certification evidence

Status: certified on immutable candidate baseline `eff0d08506c408090754c72e4ea6ca4bdcd10369`; final closeout state must pass the same full exact-head protected-main gates unchanged before merge.

## Certified scope and immutable lineage

- TASK-0043 research and TASK-0042 plan-drift reconciliation are committed in `.ai/research/PHASE-08/TASK-0043-RESEARCH.md`; TASK-0042 was never materialized or reused.
- TASK-0044 canonical AST/compiler is on protected main through `175baa5`, PostgreSQL evidence through `4134306`, and acceptance through `c227863`.
- TASK-0045 provider-neutral natural-language proposal boundary and deterministic confirmation are on protected main through `143b760`.
- TASK-0046 bounded preview/count, immutable versioning and audience UX merged through PR #412 as protected main `8f12e6668b8eded05ad6282ff61f508f64ead4dc`.
- PHASE-09 is planned and inactive. This carrier contains no journey graph, trigger, wait, action or PHASE-10 gateway implementation.

## AC-1 — Isolation and authorization

Evidence:

- `tests/Feature/Security/Task0044SegmentCompilerSecurityTest.php` rejects foreign field/event identity and proves every base/relation query receives authenticated workspace scope independently of AST data.
- `tests/Feature/Security/Task0046PreviewVersionSecurityTest.php` proves foreign pinned versions, cross-workspace preview, unauthorized actors and cross-version definition substitution fail closed.
- Preview returns `preview_members: []`; no member cache, materialization or export endpoint exists. `cache_status: disabled` and `cache_key: null` make cross-workspace cache reuse impossible in the certified implementation.
- Saved-segment discovery is workspace scoped, permission checked and limited to 50 definitions.

Result: satisfied.

## AC-2 — Injection, malformed input and AI boundary

Evidence:

- `tests/Unit/Modules/Segmentation/SegmentValidatorTest.php` covers malformed AST, unknown nodes/operators/fields, invalid types, empty/contradictory groups, depth/node/list/window bounds and canonical normalization.
- `tests/Feature/Security/Task0044SegmentCompilerSecurityTest.php` proves SQL-looking values stay bindings, identifiers/operators/joins/functions are registry owned, and unsafe wildcard/JSON/regex/raw SQL constructs are rejected.
- `tests/Unit/Modules/Segmentation/SegmentProposalBoundaryTest.php` covers prompt injection, “ignore policy”, SQL/tool/admin/cross-tenant requests, hallucinated fields/events/operators, malformed/oversized model output, retry bounds and secret/PII input rejection.
- Model output is only an untrusted structured proposal. `ConfirmSegmentProposal`, `SegmentValidator`, field/event policy and `DeterministicSegmentCompiler` own executable meaning.

Result: satisfied.

## AC-3 — Determinism and pinned versions

Evidence:

- Canonical JSON serialization, stable definition hash, stable parameter order, stable query/evaluation fingerprints and changed-definition identity are covered by validator/compiler unit and security tests.
- Immutable `segment_definition_versions` rows are append-only. Publication stores an explicit `published_version_number`; revision never mutates an earlier version or floats the published pointer.
- Pinned preview reloads by workspace + definition + version, validates the stored canonical hash, and rejects client definition mismatch.

Result: satisfied.

## AC-4 — Privacy, consent and audit safety

Evidence:

- Preview is count-only and returns no identifiers, names, addresses, event payloads or raw filter values.
- Sensitive/inferred fields are absent from the targetable registry by default; field use requires current permission and workspace registration.
- Audit events contain definition/evaluation identity and count kind, not raw predicate values or provider secrets.
- Count authorization requires `contact.read`. No universal small-cell threshold is invented without approved privacy policy.
- Segment membership is explicitly distinct from delivery eligibility. Canonical send admission remains responsible for current consent and suppression.

Result: satisfied.

## AC-5 — Scale and PostgreSQL evidence

Evidence:

- `tests/Integration/Task0044SegmentCompilerPostgresTest.php` proves representative tenant-scoped, bound compiler plans.
- `tests/Integration/Task0046SegmentPreviewPostgresTest.php` runs `EXPLAIN (FORMAT JSON)`, validates hostile values remain bindings, proves the `max_count_probe + 1` bound, and proves PostgreSQL transaction-local statement timeout cancels over-budget work.
- Compiler cost, AST depth/node count, IN-list size and event-window bounds apply before execution.
- Exact full-audience count is not issued beyond the probe. Large audiences return `capped`; database timeout/error returns secret-safe `unavailable`.
- No production latency SLO or random index is claimed; conservative limits remain configurable and explicitly non-SLO defaults.

Result: satisfied.

## AC-6 — Accessible operator experience

Evidence:

- `resources/js/pages/segmentation/operator.test.tsx` covers provider unavailable, deterministic validation error, permission denial, cost rejection, AI proposal review, confirmation, loading, exact zero/empty, estimated, capped/large, stale, freshness-known/unknown, timeout/unavailable, PII-minimized copy and pinned-version requests.
- `e2e/task0046-authenticated-segment.spec.ts` uses a guarded test-only persistent SQLite workspace and a real authenticated browser to cover keyboard start, nested AND/OR/NOT editing, inline invalid JSON, loading, exact/empty/stale/large states, bounded preview, immutable save, destructive publication confirmation and 375px responsive layout.
- `e2e/task0046-segment-preview.spec.ts` proves unauthenticated reads and CSRF-protected mutations fail without leakage.
- Controls use native labels/fieldset/legend, live/status/alert semantics, keyboard-operable buttons, non-color-only copy and mobile reflow. Test fixtures refuse non-testing, non-SQLite and in-memory environments.

Result: satisfied.

## AC-7 — Exact protected-main promotion

Immutable candidate evidence:

- PR #413 candidate head `eff0d08506c408090754c72e4ea6ca4bdcd10369` passed AI Continuity Guard run `36279523770`.
- The same head passed Application Foundation CI run `36279523802`, including backend, architecture/static/format, frontend typecheck/unit/build, PHP 8.3 floor, PostgreSQL integration and live Playwright E2E.
- The same head passed Security Supply Chain CI run `36279523720`.
- TASK-0047, phase/roadmap, README, queue, Runner evidence, state/checkpoint/journal and terminal lease/workstream state are synchronized by the final closeout commit.
- PHASE-09 remains planned/inactive and no successor task is registered or activated.

Result: satisfied for the immutable candidate baseline. The final closeout head remains merge-blocked until its own `CI-Mode: full` Continuity, Application and Security runs pass unchanged; only that exact head may merge, after which resulting protected-main status must be verified.
