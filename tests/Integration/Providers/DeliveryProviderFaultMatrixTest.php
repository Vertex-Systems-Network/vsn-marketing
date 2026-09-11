<?php

declare(strict_types=1);

/**
 * TASK-0023 / WS-0023-PROVIDER-FAULTS
 *
 * Provider-neutral delivery fault matrix to certify:
 * - timeout before provider acceptance evidence;
 * - transient and permanent normalized failures;
 * - provider rate-limit/quota observations;
 * - ambiguous outcomes that must enter reconciliation rather than replay;
 * - retry-safe proven non-acceptance and explicit failover eligibility;
 * - breaker opening/half-open recovery and retry amplification measurements.
 *
 * The executable matrix will use repository provider abstractions only. It
 * will not introduce credentials, paid sends, or provider-specific shortcuts.
 */
