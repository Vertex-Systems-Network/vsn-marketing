<?php

declare(strict_types=1);

/**
 * TASK-0023 / WS-0023-RECOVERY-DUPLICATES
 *
 * In-progress recovery and duplicate-safety certification.
 *
 * Required races/faults:
 * - worker termination before and after durable attempt creation;
 * - restart while stale leases or reconciliation work exist;
 * - dead-letter and reconciliation recovery under concurrent workers;
 * - duplicate retry/failover requests for one logical idempotency key;
 * - accepted reconciliation racing retry-safe non-acceptance/failover;
 * - physical-attempt count and recovery convergence measurements.
 *
 * The final suite must prove durable evidence wins over process-local state and
 * that recovery does not create silent duplicate provider attempts.
 */
