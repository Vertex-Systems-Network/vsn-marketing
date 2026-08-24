# VSN Marketing — Agent Operating Contract

This repository is designed to be developed by humans and multiple AI coding agents without relying on chat memory.

## Non-negotiable source of truth

The repository is the memory. Before changing anything, every agent MUST read, in order:

1. `.ai/00-PROJECT-CHARTER.md`
2. `.ai/01-MASTER-ARCHITECTURE.md`
3. `.ai/05-AI-RULES.md`
4. `.ai/10-AI-CONTROL-PLANE.md`
5. `.ai/11-RESEARCH-FIRST-STANDARD.md`
6. `.ai/12-QUALITY-ENGINEERING-GATES.md`
7. `.ai/roadmap/PREPLANNED-IMPLEMENTATION-PLAN.md`
8. `.ai/state/CURRENT-STATE.yaml`
9. `.ai/state/LAST-CHECKPOINT.md`
10. `.ai/state/EXECUTION-JOURNAL.jsonl`
11. the active task referenced by `CURRENT-STATE.yaml`
12. relevant ADRs, contracts, AI registries, research packs, and phase document

Then run:

```bash
python tools/ai_txn.py recover
python tools/ai_txn.py validate
python tools/ai_state.py recover
python tools/ai_state.py validate
python tools/ai_journal.py validate
python tools/ai_policy.py
python tools/ai_context.py manifest
python tools/ai_state.py status
python tools/ai_journal.py status
```

`ai_txn.py recover` rolls back an interrupted continuity mutation before any new work begins. `ai_state.py recover` then compares the machine ledger with the working tree and exposes continuity drift. The execution journal is append-only and hash-chained; it proves the ordered history of state handoffs. The context compiler gives the agent a deterministic ordered manifest of the exact repository sources it must use. No implementation work may begin if a validator fails. Reconcile state first.

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
- `CURRENT-STATE.yaml` and `LAST-CHECKPOINT.md` are cryptographically coupled by a state fingerprint. Never edit one without synchronizing the other.
- State/checkpoint/task-transition mutations MUST go through `tools/ai_txn.py`; direct mutation commands in `ai_state.py` / `ai_journal.py` are low-level implementation primitives, not the normal agent interface.
- The transactional wrapper creates a single-writer lock and durable worktree-local backups under `.git`, rolls back interrupted mutations on the next recovery, and records the journal event in the same guarded operation.
- Never rewrite, reorder, delete, or squash individual lines inside `.ai/state/EXECUTION-JOURNAL.jsonl`; append corrective events instead.
- Treat `tools/ai_context.py manifest` as the canonical context inventory. If its manifest changes while working, inspect the changed source before continuing.
- Product AI must follow `.ai/10-AI-CONTROL-PLANE.md` and the machine-readable registries under `.ai/ai/`; prompts cannot override deterministic tool/risk policy.
- The preplanned roadmap is a minimum known plan. Research may extend it explicitly but may never silently remove, weaken, reorder, or reinterpret existing requirements.

## Research-first protocol

Before implementing any new subsystem, provider, channel, external API, AI model/tool capability, security-sensitive mechanism, major infrastructure mechanism, regulated/compliance-sensitive feature, or mature market-category workflow, the agent MUST follow `.ai/11-RESEARCH-FIRST-STANDARD.md`.

The minimum sequence is:

1. Verify canonical repository state and the active task.
2. Inspect the preplanned task/phase and existing assumptions.
3. Research the current internet/official documentation before writing implementation code.
4. Prefer official provider/API documentation, standards, upstream docs/release notes, security guidance, and current platform policies; use competitor/reference products for workflow benchmarking where relevant.
5. Create or revalidate the dated research evidence pack under `.ai/research/<PHASE>/<TASK>-RESEARCH.md`.
6. Classify material findings as plan confirmation, prerequisite, acceptance criterion, new task, ADR, blocker, approved deferral, or no product impact.
7. Extend/reconcile the roadmap/task/ADR first when research changes scope.
8. Re-run continuity validation after planning changes.
9. Only then begin the active implementation task.

For fast-changing APIs, AI models, pricing/quotas, platform rules, security advisories, and legal/regulatory constraints, prior chat or stale repository summaries are not sufficient current evidence.

If current authoritative research is required but internet/official documentation cannot be accessed, record a blocker instead of guessing the contract.

## Quality engineering protocol

Every task must evaluate the applicable controls in `.ai/12-QUALITY-ENGINEERING-GATES.md`. Security, tenant isolation, privacy, supply chain, production parity, performance/SLO, reliability, accessibility, UI/UX, data quality, AI safety, backup/restore, and provider contract gates become acceptance requirements when relevant to the task.

A phase cannot be certified merely because feature code exists.

## Interruption / context-limit protocol

If execution is interrupted, context is nearly full, tooling fails, or the agent must stop:

1. Do not begin another task.
2. Preserve working code; do not fabricate completion.
3. Run the relevant tests that are still possible.
4. Update `.ai/state/TEST-STATE.yaml`.
5. Update the active task status and remaining acceptance criteria when required by the active task.
6. Run `python tools/ai_txn.py checkpoint --summary "..." --tests "..." --next "..."`; it updates state/checkpoint and appends the journal event as one guarded transaction.
7. Rebuild/inspect `python tools/ai_context.py manifest`.
8. Run all continuity validators before handing off.

The next agent must resume from that exact next action.

## Recovery when state and code disagree

Code, Git history, tests, migrations, checkpoint fingerprints, context manifests, research packs, and the hash-chained execution journal are evidence. Always run `python tools/ai_txn.py recover` before normal ledger recovery. If the transactional wrapper finds an interrupted mutation, it restores the pre-mutation snapshots before validation. Then run `python tools/ai_state.py recover`. If evidence still disagrees, do not guess. Set project execution status to `needs_reconciliation`, record the mismatch in `BLOCKERS.md`, inspect Git history/diff/tests/journal/context/research sources, repair the ledger through a transactional checkpoint, validate all layers, then continue.

## Completion protocol

Do not manually flip several task/state files independently. First ensure every active-task acceptance criterion is true and required tests pass, then use the transactional guarded transition command:

```bash
python tools/ai_txn.py transition \
  --complete TASK-XXXX \
  --next TASK-YYYY \
  --evidence "why completion is proven" \
  --tests "exact test evidence"
```

The transactional transition snapshots all mutated ledger files, acquires a single-writer lock, invokes the deterministic state transition, appends the matching journal event, validates state/journal/context integrity, and removes backups only after success. A normal failure rolls back immediately; a process interruption leaves durable `.git` recovery material for the next `ai_txn.py recover` invocation.

## Product safety baseline

This is a permission-based marketing platform. Never design mechanisms whose purpose is spam, consent bypass, suppression bypass, provider-limit evasion, fake-account free-tier rotation, unauthorized scraping, or sender/platform-policy circumvention.
