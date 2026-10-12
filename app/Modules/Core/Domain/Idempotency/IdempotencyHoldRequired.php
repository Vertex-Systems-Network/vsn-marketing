<?php

namespace App\Modules\Core\Domain\Idempotency;

use RuntimeException;

/**
 * Signals an operation whose side effects may be partially applied. The key is
 * held terminally instead of failed, so no retry can re-run it without an operator.
 */
class IdempotencyHoldRequired extends RuntimeException {}
