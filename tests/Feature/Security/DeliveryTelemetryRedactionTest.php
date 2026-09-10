<?php

declare(strict_types=1);

/**
 * TASK-0023 / WS-0023-SECURITY-TELEMETRY
 *
 * In-progress security certification for performance/operations telemetry.
 *
 * Required cases:
 * - credentials/tokens/provider secrets never appear in metrics or evidence;
 * - message bodies and recipient identifiers are absent or safely reduced;
 * - workspace/provider/channel dimensions cannot leak cross-tenant data;
 * - failure observations expose normalized categories, not raw secret-bearing payloads;
 * - load/fault artifacts are safe to retain as CI evidence.
 *
 * This worker owns verification only. Shared logging/telemetry implementation
 * changes, if required, are escalated to the Supervisor integration lane.
 */
