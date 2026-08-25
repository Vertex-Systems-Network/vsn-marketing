# AI Context Pack Contract

A context pack is an ordered, provenance-preserving input bundle for an AI execution.

## Development context

`python tools/ai_context.py manifest` builds the deterministic repository manifest. `python tools/ai_context.py build --out <file>` includes source contents. The manifest hash changes when any ordered source, checksum, active task, phase, next task, or exact next action changes.

A stale manifest must be rejected or rebuilt.

## Mandatory development source classes

The deterministic compiler must include the repository sources that can materially constrain execution rather than relying on a hand-curated subset that may silently omit governance.

At minimum the manifest includes:

- `AGENTS.md` and the cross-cutting `.ai/00-...` through `.ai/12-...` governance/architecture standards that exist in the repository;
- canonical execution state, checkpoint, blockers, test state, append-only execution journal, task index and active task file;
- canonical roadmap state, master roadmap, long-horizon preplanned implementation plan and current phase document when present;
- the active task research pack when one exists under `.ai/research/<PHASE>/<TASK>-RESEARCH.md`;
- provider/integration capability and catalog governance required by external-integration work;
- canonical architecture decisions under `.ai/adr/ADR-*.md`;
- legacy `.ai/decisions/ADR-*.md` records while that historical namespace still exists, excluding templates, so continuity decisions are not erased by directory migration;
- AI policy/agent/model/prompt/tool/evaluation/memory/observability registries;
- all canonical `.ai/contracts/*.md` contracts.

A future compiler change may add source classes, but it may not remove a required governance class without an explicit architecture/governance decision and regression coverage.

## Required properties

- deterministic source order;
- source path and SHA-256;
- active task and phase binding;
- exact next action;
- explicit provenance;
- no hidden chat-memory dependency;
- optional content separated from manifest identity;
- canonical ADR and active-research discoverability;
- fail-fast behavior when a mandatory base source is missing.

## Product context

The same contract will be implemented for workspace/brand/customer/run contexts with scope IDs, data classification, consent/purpose, redaction decisions, freshness timestamps, source versions, token/size budgets and provenance.

Context assembly may omit irrelevant product data according to policy and budget, but it may not fabricate missing source facts or silently exclude deterministic governance required for the execution being authorized.
