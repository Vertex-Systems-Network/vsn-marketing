<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessConnectorWebhook implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public string $connector;
    public string $rawBody;
    public array $headers;
    public ?string $dedupId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $connector, string $rawBody, array $headers, ?string $dedupId = null)
    {
        $this->connector = $connector;
        $this->rawBody = $rawBody;
        $this->headers = $headers;
        $this->dedupId = $dedupId;

        $this->onQueue('connectors');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Placeholder: Implement provider-specific processing here.
        // Decode JSON if possible and log the event for now.
        $payload = json_decode($this->rawBody, true);

        Log::info('Processing connector webhook job', [
            'connector' => $this->connector,
            'dedup_id' => $this->dedupId,
            'payload_preview' => is_array($payload) ? array_slice($payload, 0, 10) : substr($this->rawBody, 0, 1024),
        ]);

        // TODO: Dispatch provider-specific processing, map to domain events, etc.
    }
}
