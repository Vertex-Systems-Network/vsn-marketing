# TASK-0048 — PHASE-09 Research Pack

**Title:** Research journey/automation engines, concurrency, waits, market builders, and replay/failure patterns  
**Captured:** 2026-09-27 UTC  
**Dependency:** TASK-0047 (PHASE-08 final acceptance)  
**Protected-main anchor reconciled:** `a28d48f6dcc73f79d71c3a13d769bf6a871e709a` (PR #413 merged)

## Scope and boundary

This is the research gate for PHASE-09, the deterministic journey and automation engine. It covers domain model, durable execution, security, consent, scale, and operator UX before implementation. It does not implement journeys, providers, or AI agents. PHASE-10 remains `planned` and `inactive`; TASK-0054 through TASK-0061 and any PHASE-10 code/state are out of scope.

The prior state snapshot referenced `8f12e666…` and PHASE-08. That was a stale pre-merge observation. PR #413 completed PHASE-08 on protected main at `a28d48f…`; this pack reconciles the anchor before PHASE-09 activation. TASK-0042 remains an intentional unmaterialized identifier gap because PHASE-07 certification was satisfied in TASK-0041 final acceptance / PR #405.

## Current authoritative patterns

| Area | Evidence and finding | Classification |
|---|---|---|
| Durable execution | Temporal describes workflow execution as a durable unit that can resume after failures; its activity model supplies timeouts, retry, and failure handling. | `CONFIRMS_PLAN`, `NEW_ACCEPTANCE_CRITERION` |
| Long-running waits | AWS Step Functions Standard workflows are long-running and auditable; Wait states support relative seconds or absolute timestamps. | `CONFIRMS_PLAN`, `ADR_REQUIRED` |
| Explicit branching | Step Functions Choice states require explicit predicates and recommend a default transition. | `CONFIRMS_PLAN`, `NEW_ACCEPTANCE_CRITERION` |
| Retry/failure | AWS documents bounded Retry/Catch handling; Temporal documents activity retry and timeout semantics. Unsafe side effects must never retry without an idempotency key. | `CONFIRMS_PLAN`, `NEW_ACCEPTANCE_CRITERION` |
| Enrollment/re-entry | HubSpot exposes enrollment, unenrollment, suppression, and re-enrollment settings. Customer.io evaluates exit conditions before each journey/action; Klaviyo documents re-entry and flow timing. | `CONFIRMS_PLAN`, `NEW_ACCEPTANCE_CRITERION` |
| Goals/exits | Customer.io and Braze model conversion/goal and exit criteria separately from entry triggers. | `CONFIRMS_PLAN` |
| Scale guard | Customer.io warns when a trigger would fan out to more than 1,000 starts. This is a guard pattern, not a VSN production SLO. | `NEW_ACCEPTANCE_CRITERION`, `DEFER_WITH_APPROVAL` |
| Immutable live definitions | Customer.io notes that live workflows cannot be freely edited after activation. VSN executions must pin an immutable journey version. | `CONFIRMS_PLAN`, `ADR_REQUIRED` |
| Provider neutrality | VSN provider/channel contracts already require capability checks, consent/suppression, authorization, quotas, and provider-neutral errors. | `CONFIRMS_PLAN` |

Representative current sources (retrieved 2026-09-27 UTC):

- Temporal workflow execution: https://docs.temporal.io/workflow-execution
- Temporal activity retries/timeouts: https://docs.temporal.io/activity-execution
- AWS Standard/Express workflow types: https://docs.aws.amazon.com/step-functions/latest/dg/choosing-workflow-type.html
- AWS Retry/Catch: https://docs.aws.amazon.com/step-functions/latest/dg/concepts-error-handling.html
- AWS Wait state: https://docs.aws.amazon.com/step-functions/latest/dg/state-wait.html
- AWS Choice state: https://docs.aws.amazon.com/step-functions/latest/dg/state-choice.html
- HubSpot enrollment/re-enrollment: https://knowledge.hubspot.com/workflows/manage-enrollment-triggers
- Customer.io journeys and exit conditions: https://customer.io/docs/journeys/ and https://customer.io/docs/journeys/exit-conditions/
- Customer.io wait-until: https://customer.io/docs/journeys/wait-until/
- Braze Canvas exit/re-entry: https://www.braze.com/docs/user_guide/engagement_tools/canvas/creating_a_canvas/exit_criteria
- Klaviyo flow timing: https://help.klaviyo.com/hc/en-us/articles/115001074492

## Canonical PHASE-09 domain model

1. **Journey and version.** A journey is a workspace-owned named resource. Publishing creates an immutable version containing canonical graph, trigger policy, consent/suppression policy, goals, exits, and execution limits. Draft edits create a new revision; executions store exact `journey_version_id` and definition hash.
2. **Graph registry.** Executable meaning comes only from registered node kinds and typed configuration: `trigger`, `wait`, `condition`, `branch`, `action`, `goal`, `exit`, and explicit `end`. No SQL, code, arbitrary provider payload, or user-supplied function is executable.
3. **Enrollment.** An enrollment is workspace- and subject-scoped, with unique enrollment identity, trigger event identity, enrollment timestamp, and pinned version. Re-entry is explicit (`never`, `after_exit`, or bounded policy); duplicate event delivery cannot create duplicate enrollments.
4. **Events and ordering.** Canonical events are normalized before journeys, carry immutable `event_id`, `occurred_at`, `received_at`, and workspace identity, and use the durable outbox. Event-time ordering is preferred; late events follow documented policy. Waits use UTC instants plus explicit workspace timezone for calendar interpretation.
5. **Waits and timers.** A wait is durable state, not a worker sleep. It is a timer/deadline or wait-until predicate with bounded maximum duration. Resume is idempotent and re-checks consent, suppression, cancellation, and exit policy before side effects.
6. **Conditions, goals, exits.** Conditions and branches use allowlisted operators over registered fields/events. Goals and exits are first-class policies evaluated at enrollment and around relevant nodes. A goal can complete a journey; an exit removes it without claiming unfinished actions succeeded.
7. **Actions.** Actions resolve registered provider capabilities and immutable input snapshots. They pass authorization, consent/suppression, quota, approval, and idempotency gates. Provider errors normalize into retryable, non-retryable, rate-limited, and permanently blocked classes.
8. **Lifecycle.** Draft → validated → published → active → paused/archived. Activation is explicit confirmation. Archived versions remain readable for audit and historic execution.

Supported first: registered event/attribute triggers, bounded waits, typed conditions, deterministic branches, registered provider actions, goals, exits, cancellation, pause/resume, and pinned versions. Unsupported until separately justified: arbitrary code, SQL/JSON expressions, unbounded loops, unrestricted fan-out, dynamic provider selection, hidden joins, and AI-generated executable nodes.

## Concurrency, replay, and failure design

- Use execution identity `(workspace_id, journey_version_id, subject_id, enrollment_id)` and node-attempt identity `(execution_id, node_id, attempt)`. Persist deterministic idempotency keys before side effects.
- Claim runnable nodes with a lease or optimistic version check. Duplicate workers observe completed attempts or retry the same key; they cannot create a second provider side effect.
- Persist execution state, timer deadlines, transition history, normalized error class, retry count, and cancellation state. Use inbox/outbox boundaries for events and actions.
- Retries are bounded by node policy and workspace budget. Unknown-outcome or non-idempotent actions fail closed into operator review rather than blind retry.
- Replay reuses the pinned version and recorded transition history; it never evaluates a newer draft. Recovery reclaims expired leases and resumes from the last durable transition.
- Fan-out and enrollment storms require configurable limits, backpressure, cancellation, and per-workspace concurrency accounting. Numeric production SLOs are not invented; benchmark evidence establishes them before hardening defaults.

## Privacy, tenant isolation, and authorization

- Workspace predicates are runtime-owned and cannot be removed by graph configuration. Every enrollment, event lookup, action, cache key, materialization, and audit record carries workspace identity.
- Journeys may consume a pinned segment version but must not mutate or reinterpret it. Suppression and consent are re-evaluated immediately before every externally visible action.
- Sensitive attributes are policy-registered and permission-checked; previews/logs use stable identifiers, not raw secrets or filter values.
- Provider connections and secrets resolve by capability ID and authorization context. A graph cannot name arbitrary credentials, endpoints, tables, columns, functions, or code.

## Cost and operational controls

Use safe configurable defaults (non-production until benchmarked) for graph depth, node count, wait duration, branch/fan-out, enrollment rate, concurrent executions, retry budget, preview size, and execution history. Long waits and exact audience expansion are asynchronous. Every evaluation has cancellation, timeout/dead-letter, and auditable execution identity. No unbounded synchronous work is allowed.

## Operator UX and accessibility

The builder must support keyboard navigation, screen-reader labels for nodes/edges, focus management after add/remove, a text validation summary, non-color-only errors, responsive reflow, and explicit publish/activate/pause/cancel confirmation. A simulator evaluates a draft against fixtures without sending actions. An execution timeline distinguishes queued, waiting, running, succeeded, retried, blocked, cancelled, exited, and failed. Large graphs need collapsed groups and bounded rendering; loading, empty, stale, timeout, and permission-denied states are first-class.

## Research-to-plan reconciliation

- `CONFIRMS_PLAN`: TASK-0049 graph/version registry; TASK-0050 triggers, waits, conditions, goals, exits; TASK-0051 durable concurrency/retry/replay; TASK-0052 builder/simulator/timeline; TASK-0053 certification.
- `NEW_ACCEPTANCE_CRITERION`: immutable pinned version; pre-side-effect consent/suppression re-check; deterministic idempotency keys; explicit default branches; durable timers; no duplicate enrollment on redelivery; replay/fault evidence; fan-out/backpressure guard; accessible validation summary.
- `ADR_REQUIRED`: journey version lifecycle; event-time ordering/late-event policy; retry handling for unknown provider outcomes; pause semantics for in-flight actions; execution retention and replay window.
- `NEW_PREREQUISITE`: benchmark representative event/enrollment fan-out before production numeric limits are approved. Track under TASK-0051/TASK-0053; do not invent SLOs in this gate.
- `DEFER_WITH_APPROVAL`: randomized experimentation, arbitrary code nodes, unrestricted third-party actions, autonomous AI journey authoring, and any PHASE-10 agent/gateway capability.
- `BLOCKER`: none for PHASE-09 research activation. PHASE-10 remains a hard boundary, not a blocker.

## Acceptance decision

TASK-0048 is accepted for PHASE-09 activation. The next implementation task is TASK-0049, with TASK-0050–TASK-0053 registered as dependent work. PHASE-10 is intentionally not materialized, activated, or modified beyond retaining planned/inactive roadmap status.
