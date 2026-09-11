<?php

declare(strict_types=1);

/**
 * TASK-0023 / WS-0023-POSTGRES-CONTENTION
 *
 * In-progress production-parity certification surface.
 *
 * Required scenarios for this worker:
 * - concurrent admission against the same workspace/provider/operation route;
 * - lock contention around delivery operation, quota, breaker, and recovery rows;
 * - bounded waiting/deadlock retry behavior with no duplicate logical delivery;
 * - stale worker transaction rollback followed by deterministic recovery;
 * - measured lock-wait and completion latency evidence under steady and burst load.
 *
 * This starter commit intentionally adds no passing placeholder assertion. The
 * executable PostgreSQL cases will be added against RUN_INFRA_INTEGRATION=true
 * once the fixture is extracted from the existing delivery concurrency suite.
 */
