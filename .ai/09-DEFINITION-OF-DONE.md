# Definition of Done

A task is done only when all applicable conditions are true:

- acceptance criteria are explicitly marked complete;
- implementation follows module boundaries/contracts;
- tests required by the task pass;
- no relevant existing tests were weakened or skipped without approved rationale;
- security/tenant/consent/suppression impacts are covered;
- migrations and rollback implications are addressed;
- documentation/contracts are updated when behavior changed;
- no secrets or debug artifacts are committed;
- task registry and current state agree;
- `LAST-CHECKPOINT.md` records the completed state and exact next task/action;
- `python tools/ai_state.py validate` passes.

If any condition is false, status remains `in_progress` or `blocked`.
