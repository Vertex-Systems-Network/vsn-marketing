<?php

namespace App\Modules\Providers\Domain\Connectors\Contracts;

use App\Modules\Providers\Domain\Connectors\WebhookRequest;
use App\Modules\Providers\Domain\Connectors\WebhookVerificationResult;

interface WebhookVerifier
{
    public function verify(WebhookRequest $request): WebhookVerificationResult;
}
