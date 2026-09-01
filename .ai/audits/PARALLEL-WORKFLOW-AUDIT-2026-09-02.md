# Deep Audit — Parallel Multi-Agent Repository Workflow

Date: 2026-09-02

## Scope

Audit the existing AI-native repository governance against a Supervisor/worker multi-agent development model intended to increase throughput without allowing state, path, contract, or merge conflicts.

## Findings

1. **Module architecture is suitable for parallelism.** Canonical module boundaries and provider-neutral contracts already reduce coupling.
2. **The lifecycle ledger must remain single-top-level-task.** Allowing several agents to independently advance global task state would create journal/checkpoint races. Parallelism therefore belongs inside one active task as workstreams.
3. **Branch creation must precede planning writes.** Without this invariant the plan can reference branches that do not exist and agents may diverge from different bases.
4. **Chat-only ownership is insufficient.** Branch, agent, worktree, paths, dependency, merge group, and lease must be machine-readable.
5. **Shared files are the highest conflict surface.** Global AI state, task/roadmap registries, workflows, manifests, migrations, Core, config/routes, and canonical contracts require Supervisor ownership.
6. **Completion needs a durable signal.** `Work Done and Submitted` must be present in the registered non-draft PR, not only chat.
7. **Supervisor preemption must be explicit.** Submitted worker work is reviewed before optional Supervisor feature work resumes.
8. **Every merge invalidates stale branches.** Remaining agents must synchronize latest main before writable resume/submission.
9. **README drift is operational risk.** Material agent-working instruction changes must update README and a deterministic instruction fingerprint.
10. **New-agent admission requires capacity control.** A new agent must start from main, receive only an existing open slot, and be rejected with `Go Home Come Back Next Time` when capacity is full.

## Decision

Implement a reusable Parallel Development Control Plane with:

- one canonical active task plus multiple registered workstreams;
- Supervisor-owned main integration;
- pre-created branches;
- exclusive leases and disjoint paths;
- shared-path lock;
- deterministic merge groups;
- exact PR completion signal;
- broadcast issue #43;
- latest-main resume barrier;
- README instruction fingerprint;
- main-first deterministic onboarding and capacity rejection;
- default 6, target 10, hard-cap 12 concurrent writers;
- CI/negative tests that fail closed.

TASK-0017 is the pilot because SES, Brevo, Gmail, research, contract-matrix, and integration lanes can be separated cleanly. All lanes remain staged with zero worker leases until TASK-0017 is canonical.
