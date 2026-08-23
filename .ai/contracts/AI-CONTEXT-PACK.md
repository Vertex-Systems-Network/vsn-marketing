# AI Context Pack Contract

A context pack is an ordered, provenance-preserving input bundle for an AI execution.

## Development context

`python tools/ai_context.py manifest` builds the deterministic repository manifest. `python tools/ai_context.py build --out <file>` includes source contents. The manifest hash changes when any ordered source, checksum, active task, phase, next task, or exact next action changes.

A stale manifest must be rejected or rebuilt.

## Required properties

- deterministic source order;
- source path and SHA-256;
- active task and phase binding;
- exact next action;
- explicit provenance;
- no hidden chat-memory dependency;
- optional content separated from manifest identity.

## Product context

The same contract will be implemented for workspace/brand/customer/run contexts with scope IDs, data classification, consent/purpose, redaction decisions, freshness timestamps, source versions, token/size budgets and provenance.

Context assembly may omit irrelevant data but may not fabricate missing source facts.
