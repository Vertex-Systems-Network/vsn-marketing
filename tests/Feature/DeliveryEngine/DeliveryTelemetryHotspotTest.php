<?php

declare(strict_types=1);

/**
 * TASK-0023 / WS-0023-OBSERVABILITY
 *
 * In-progress operational telemetry certification for workspace isolation.
 *
 * Required evidence:
 * - queue age, throughput, holds and error classes attributable by workspace;
 * - provider-connection/operation-class hotspots visible without message data;
 * - channel saturation and breaker/quota blocking reasons observable;
 * - one workspace cannot read or aggregate another workspace's telemetry;
 * - bounded-cardinality identifiers suitable for production diagnostics.
 *
 * Executable feature cases will be added after identifying the canonical
 * telemetry seam; this worker will not add shared runtime wiring directly.
 */
