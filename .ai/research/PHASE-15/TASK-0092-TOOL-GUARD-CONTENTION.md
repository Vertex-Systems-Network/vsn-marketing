# TASK-0092 — Equivalent AI Tool Write Contention

Status: local implementation and adversarial test; TASK-0092 AC-2 remains open.

The earlier rollback hold was checked before acquiring the per-operation idempotency key. Two different keys with identical tool arguments could both pass that read before one handler failed its rollback. `IdempotentExecutor::runGuarded` now claims a second, argument-scoped durable key before entering the handler. A competing key sees `processing` and cannot execute; a failed rollback turns both the operation key and guard into `held`. A safely completed or safely failed invocation releases the guard to the retryable `failed` state. A worker crash while the guard is `processing` remains fail-closed for operator reconciliation.

The guard includes workspace, brand, tool ID/version and arguments in its SHA-256 key. No raw arguments, provider error text or secret is stored in the guard. The handler remains behind the registered tool and independent approval gates. This does not authorize live sending, provider execution, incident closure, or automatic hold release.

`Task0092TypedToolFailureRedTeamTest` verifies blocked overlap, durable hold, unrelated argument isolation and safe retry after non-hold failure. `AutonomyToolGuardContentionPostgresTest` uses two independent PostgreSQL worker processes: the first holds the guard during simulated provider failure, the second key is denied before its closure runs, and the first then records the terminal hold. This proves a bounded local race fixture, not a production throughput or provider safety claim.

The PHASE-10 offline measurement harness does not invoke `AiTypedToolExecutor`. A local recapture on this source produced the same 40 completed synthetic samples, prompt/context hashes and 80 settled fixture units. Only that file's changed source hash was rebound in `OFFLINE-MEASUREMENTS.v1.json`; previous elapsed timings and claims were not replaced or promoted to production evidence.
