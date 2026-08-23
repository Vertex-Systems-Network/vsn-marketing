# AI-Native Control Plane

This document is the canonical implementation boundary for product AI and development-AI continuity in VSN Marketing.

## Design laws

1. Models reason; deterministic software authorizes and executes.
2. No workflow may depend on one model vendor, one prompt, or one provider SDK.
3. Every execution-driving AI output is schema-validated before use.
4. Every privileged tool call is evaluated against workspace policy, consent, suppression, RBAC, quotas, approvals, and risk.
5. AI memory is layered and scoped; chat history is never the project source of truth.
6. Prompt/model/tool changes are versioned, evaluated, observable, reversible, and auditable.
7. High-risk actions require deterministic approval gates. AI cannot redefine its own risk tier or permissions.
8. Failures degrade safely: retry boundedly, fall back only to compatible models/tools, and never silently weaken policy.

## Runtime AI pipeline

```text
Goal / event
  -> Context Assembler
  -> Agent Planner
  -> AI Gateway
  -> Structured Output Validator
  -> Policy / Risk Engine
  -> Typed Tool Executor
  -> Domain service
  -> Event + audit + cost telemetry
  -> Evaluator / learning loop
```

## AI Gateway

The gateway owns model portability. Callers request capabilities, not model names. Routing considers structured-output support, tool use, context capacity, latency budget, cost budget, reliability, region/data policy, task class, and fallback compatibility.

A model route is invalid if it cannot satisfy every required capability or policy constraint. Fallback may change provider/model but never schema, permissions, or risk policy.

## Specialized agents

Canonical agents are machine-registered in `.ai/ai/AGENT-REGISTRY.yaml`. Agents have narrow objectives, allowed tool classes, maximum autonomous risk tier, required output schema, and memory scopes. One unrestricted super-agent is forbidden.

## Prompt registry

Prompts are immutable versions. A prompt alias may move to a newer version only after its required evaluation suite passes. Production executions record prompt ID/version, model route, schema version, tool calls, policy decisions, cost, latency, and outcome.

## Memory model

- Repository memory: architecture, tasks, ADRs, contracts, checkpoints, execution journal.
- Workspace knowledge: brand, products, policies, approved claims, templates, channel settings.
- Customer memory: consented profile, events, engagement, commerce and lifecycle state.
- Run memory: bounded scratch state for one workflow execution.
- Learned artifacts: evaluated insights/models/strategies; never raw self-modification.

Cross-workspace memory leakage is forbidden. Secrets are referenced, not embedded in context.

## Context packs

Development agents consume deterministic repository context packs compiled by `tools/ai_context.py`. Product agents will use the same principle: ordered sources, explicit scope, checksums, provenance, size budgets, and redaction before model invocation.

## Tool execution

Tools are typed and permissioned. The model proposes a tool call; deterministic code validates:
- schema;
- actor/workspace;
- permission;
- consent/suppression where relevant;
- risk tier;
- quota/budget;
- idempotency;
- approval requirements;
- data classification.

The tool executor emits an auditable result envelope. Free-form model text never directly mutates production state.

## Autonomy tiers

The canonical policy is `.ai/ai/AUTONOMY-POLICY.yaml`.

- R0: read/analyze/draft only.
- R1: reversible low-impact writes within explicit policy.
- R2: bounded operational changes with limits, validation, and rollback.
- R3: high-impact actions requiring human or separately authorized deterministic approval.

Production send, credentials, billing, destructive operations, architecture/security policy changes, sending identity changes, and generated connector activation cannot be downgraded by an agent.

## Observability and budgets

Every AI execution must be traceable by correlation ID and record:
- agent;
- prompt/version;
- requested capabilities;
- selected route and fallback path;
- input/output schema versions;
- context manifest hash;
- tool calls;
- policy decisions;
- latency;
- token/usage metrics where available;
- estimated/actual cost where available;
- evaluation/outcome signals.

Budgets are workspace policy, not prompt instructions.

## Evaluation gates

AI changes are tested at four levels:
1. schema/contract tests;
2. deterministic policy and tool tests;
3. golden/task-specific evaluations;
4. controlled production canaries with rollback.

An optimization cannot promote itself based only on its own judgment. Promotion requires externally computed evaluation thresholds.

## Self-improvement boundary

AI may propose prompts, strategies, routing changes, segments, experiments, and connector code. Proposed changes enter a versioned candidate state. Tests/evals/policy gates decide promotion. The running agent cannot overwrite its own governing contracts, tests, approval policy, or evaluation thresholds.

## Connector Engineer boundary

Future API integration follows: documentation/OpenAPI ingest -> capability map -> generated adapter candidate -> static checks -> contract tests -> sandbox -> security review -> canary -> active. Provider-specific behavior remains behind canonical adapters.

## Failure policy

- malformed structured output: reject or bounded retry;
- model unavailable: capability-compatible fallback;
- tool timeout: idempotent bounded retry;
- policy uncertainty: fail closed;
- stale context: rebuild context pack;
- conflicting repository state: reconciliation mode;
- budget exhaustion: stop or downgrade only to an explicitly allowed compatible route;
- repeated provider failure: circuit breaker.

No failure mode may bypass consent, suppression, security, or approvals.
