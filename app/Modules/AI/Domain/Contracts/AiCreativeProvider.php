<?php

namespace App\Modules\AI\Domain\Contracts;

/** Portable offline creative fixture protocol; future live adapters require separate activation. */
interface AiCreativeProvider extends AiOfflineAdapter {}
