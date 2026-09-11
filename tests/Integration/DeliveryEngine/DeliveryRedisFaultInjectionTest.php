<?php

declare(strict_types=1);

/**
 * TASK-0023 / WS-0023-REDIS-FAULTS
 *
 * In-progress Redis production-parity fault certification.
 *
 * Required scenarios:
 * - admission-lock Redis interruption before capacity acquisition;
 * - Redis latency while durable PostgreSQL state remains authoritative;
 * - capacity lease loss/expiry during worker termination;
 * - reconnect/recovery without creating an extra provider attempt;
 * - backpressure when Redis coordination is unavailable;
 * - measured recovery convergence and queue-age impact.
 *
 * No placeholder pass is declared here. Executable cases will reuse the
 * existing RedisDeliveryAdmissionCoordinator integration fixture and run only
 * with RUN_INFRA_INTEGRATION=true.
 */
