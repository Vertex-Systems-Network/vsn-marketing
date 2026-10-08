# Recovery Protocol

Use this on every fresh Supervisor session, `continue`/resume, interruption, tool/connector failure, or message-delivery timeout.

1. Run `python tools/ai_txn.py recover`, then validate compact state.
2. Read only `CURRENT-STATE.yaml` and `LAST-CHECKPOINT.md` first.
3. Resolve the exact current default/protected `main` SHA and compare it with `observed_main_sha` using `python tools/supervisor_contract.py validate-main-observation --current-main <sha>`. Treat `observed_main_sha` as a snapshot-basis anchor: an exact match is current; an anchor descendant containing only approved durable reconciliation surfaces is also current and MUST NOT trigger another recursive state-only PR; all other drift requires reconciliation.
4. Reconcile all open Issues, then all open PRs/MRs. Accepted actionable work cannot be bypassed.
5. Re-read the active task/research claims, `.ai/coordination/OPEN-WORK-QUEUE.yaml`, and `.ai/runner/RUNNER-BENCHMARK.yaml`.
6. Inspect relevant commits since the recorded anchor. Reconcile merged/closed work and stale queue/Runner states.
7. Read archived journal/checkpoint history only when a specific evidence conflict requires it.
8. Never replay a branch/file/PR/merge/migration/provider/deployment/destructive/runtime action because a prior chat response was missing.
9. If evidence conflicts, set/reconcile a blocked or needs-reconciliation state, persist the conflict, repair/recover the affected action from repository evidence, and continue other safe dependency-ready work. Do not ask the user to resolve ordinary technical drift.
10. Before handoff run `python tools/supervisor_contract.py validate`, `python tools/runner_benchmark.py validate`, continuity validators, and resume only from `exact_next_safe_action`.

CI is a durable external boundary for the dependent artifact, not for the whole active 5-hour batch. Use bounded backoff: up to 4 normal exact-head observations per gate cycle and up to 12 with a durable material state-transition exception; tight polling remains forbidden. If checks remain running, preserve VERIFYING/WAITING_EXTERNAL evidence, continue safe dependency-ready work that does not consume the pending artifact, and use the remaining bounded observation budget. While the current host turn can still execute, do not end with an external-wait status reply; keep observing statefully until the check resolves or an actual credit/context/tool stop is observed. CI waiting never requires a user confirmation and is not, by itself, a terminal conversation condition. Do not create a source-head state-only commit merely to narrate pending CI.

Repository/runtime evidence outranks chat memory. Compact state is only a resume index.
