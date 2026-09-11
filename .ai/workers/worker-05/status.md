# Worker 05: Provider Faults

## Assignment
**Task:** TASK-0024 - PHASE-04 Certification  
**Workstream:** WS-0024-AC5-FAIL-CLOSED  
**Owner:** worker-05  
**Status:** IN_PROGRESS  

## Objective
Verify cross-workspace, unsupported capability, stale/unknown quota, rate-limit, provider outage, duplicate event, replay and policy-denial regressions fail closed with auditable state.

## Current Progress
- [x] Branch created and synced with origin
- [ ] Implement cross-workspace isolation tests
- [ ] Implement unsupported capability tests
- [ ] Implement quota/rate-limit failure tests
- [ ] Implement provider outage tests
- [ ] Implement duplicate event/replay tests
- [ ] Implement policy-denial tests
- [ ] Push changes to remote

## Files to Modify
- `.ai/tasks/TASK-0024.yaml` - Update AC-5 status
- `tests/Integration/Delivery/FailClosedTest.php`
- `tests/Integration/Policy/PolicyDenialTest.php`
- `tests/Integration/Capability/UnsupportedCapabilityTest.php`

## Last Commit
None yet - initialization in progress

## Next Action
Implement cross-workspace isolation tests to verify fail-closed behavior.
