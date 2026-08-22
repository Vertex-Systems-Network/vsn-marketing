# VSN Marketing — Agent Operating Contract

This repository is designed to be developed by humans and multiple AI coding agents without relying on chat memory.

## Non-negotiable source of truth

The repository is the memory. Before changing anything, every agent MUST read, in order:

1. `.ai/00-PROJECT-CHARTER.md`
2. `.ai/01-MASTER-ARCHITECTURE.md`
3. `.ai/05-AI-RULES.md`
4. `.ai/state/CURRENT-STATE.yaml`
5. `.ai/state/LAST-CHECKPOINT.md`
6. the active task referenced by `CURRENT-STATE.yaml`
7. relevant ADRs, contracts, and phase document

Then run:

```bash
python tools/ai_state.py validate
python tools/ai_state.py status
```

No implementation work may begin if validation fails. Reconcile state first.

## Execution rules

- Work only on the active task unless the user explicitly changes priority and the state is updated first.
- Do not silently change architecture, stack, module boundaries, canonical contracts, security policy, or product terminology. Create an ADR and mark it `PROPOSED` first.
- Do not start a task whose dependencies are incomplete.
- Do not mark work complete while required tests fail or acceptance criteria are false.
- Do not delete, skip, weaken, or rewrite tests merely to make CI green.
- Provider-specific code belongs behind adapters. Core business logic must not call provider SDKs directly.
- AI may propose and plan; deterministic policy engines control consent, suppression, permissions, quotas, approvals, billing, security, and delivery eligibility.
- Secrets must never be committed or exposed to an AI prompt when a credential reference can be used instead.
- Every meaningful work session must leave a checkpoint before stopping.

## Interruption / context-limit protocol

If execution is interrupted, context is nearly full, tooling fails, or the agent must stop:

1. Do not begin another task.
2. Preserve working code; do not fabricate completion.
3. Run the relevant tests that are still possible.
4. Update `.ai/state/TEST-STATE.yaml`.
5. Update the active task status and remaining acceptance criteria.
6. Write `.ai/state/LAST-CHECKPOINT.md` with exact completed work, incomplete work, modified files, blockers, test results, and the exact next action.
7. Update `.ai/state/CURRENT-STATE.yaml` atomically with the checkpoint.

The next agent must resume from that exact next action.

## Recovery when state and code disagree

Code, Git history, tests, and migrations are evidence; the state files are the execution ledger. If they disagree, do not guess. Set project execution status to `needs_reconciliation`, record the mismatch in `BLOCKERS.md`, inspect Git history/diff/tests, repair the ledger, validate it, then continue.

## Completion protocol

Before completing a task:

```bash
python tools/ai_state.py validate
```

All task acceptance criteria must be true and required test state must be passing. Update the task, checkpoint, state, and task registry in the same change set as the implementation.

## Product safety baseline

This is a permission-based marketing platform. Never design mechanisms whose purpose is spam, consent bypass, suppression bypass, provider-limit evasion, fake-account free-tier rotation, or sender-policy circumvention.
