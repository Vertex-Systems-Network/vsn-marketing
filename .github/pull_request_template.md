## Task

- Active task: `TASK-XXXX`
- Phase: `PHASE-XX`

## What changed

Describe implementation changes and why they satisfy the active task.

## Acceptance criteria

- [ ] Task acceptance criteria updated truthfully.
- [ ] No future-task work was mixed into this PR.
- [ ] Architecture changes have an ADR.
- [ ] Provider-specific logic stays behind adapters where applicable.
- [ ] Security/tenant/consent/suppression impact reviewed.

## Verification

- [ ] `python tools/ai_state.py validate`
- [ ] Relevant application/unit/integration tests
- [ ] `.ai/state/TEST-STATE.yaml` updated when test state changed
- [ ] `.ai/state/LAST-CHECKPOINT.md` contains exact next action
- [ ] `.ai/state/CURRENT-STATE.yaml` matches repository reality

## Recovery notes

If this PR is interrupted, state exactly what remains and the first next command/action. Never mark incomplete work complete merely to merge.
