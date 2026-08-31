<?php

declare(strict_types=1);

namespace App\Modules\Connectors\Presentation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Connectors\Contracts\WebhookVerifierInterface;
use App\Connectors\Dedup\DedupStoreInterface;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller
{
    protected WebhookVerifierInterface $verifier;
    protected DedupStoreInterface $dedup;
    protected LoggerInterface $logger;

    public function __construct(WebhookVerifierInterface $verifier, DedupStoreInterface $dedup, LoggerInterface $logger)
    {
        $this->verifier = $verifier;
        $this->dedup = $dedup;
        $this->logger = $logger;
    }

    public function handle(Request $request, string $connector): JsonResponse
    {
        // Preserve raw body
        $rawBody = (string) $request->getContent();

        // Normalize headers: Symfony request headers -> simple array
        $headers = [];
        foreach ($request->headers->all() as $k => $v) {
            $headers[$k] = $v;
        }

        // Verify authenticity
        try {
            $verified = $this->verifier->verify($rawBody, $headers);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook verifier threw exception', ['connector' => $connector, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'verifier_error'], 400);
        }

        if (!$verified) {
            $this->logger->warning('Webhook verification failed', ['connector' => $connector]);
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        // Deduplication: extract id and check
        $dedupId = $this->verifier->deduplicationId($rawBody, $headers);
        if (!empty($dedupId)) {
            if ($this->dedup->has($dedupId)) {
                $this->logger->info('Duplicate webhook received', ['id' => $dedupId, 'connector' => $connector]);
                // For idempotency, return success but avoid reprocessing
                return response()->json(['status' => 'duplicate'], 200);
            }

            // Record dedup id with default TTL
            $this->dedup->record($dedupId);
        }

        // TODO: dispatch processing job for the connector (async)
        $this->logger->info('Webhook accepted', ['connector' => $connector, 'dedup_id' => $dedupId]);

        return response()->json(['status' => 'accepted'], 202);
    }
}
