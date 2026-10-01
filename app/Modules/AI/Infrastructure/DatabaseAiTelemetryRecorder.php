<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Stores references and cost/status only; never prompt, context or model output. */
final class DatabaseAiTelemetryRecorder implements AiTelemetryRecorder
{
    public function begin(string $workspaceId, string $attemptId, array $route, array $request): bool
    {
        foreach (['trace_id', 'prompt_id', 'prompt_version', 'context_manifest_sha256'] as $field) {
            if (! is_string($request[$field] ?? null) || $request[$field] === '') {
                return false;
            }
        }

        if (strlen($request['context_manifest_sha256']) !== 64
            || ! ctype_xdigit($request['context_manifest_sha256'])) {
            return false;
        }

        return DB::table('ai_gateway_traces')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'attempt_id' => $attemptId,
            'trace_id' => $request['trace_id'],
            'route_id' => $route['id'],
            'route_version' => $route['version'],
            'prompt_id' => $request['prompt_id'],
            'prompt_version' => $request['prompt_version'],
            'context_manifest_sha256' => strtolower($request['context_manifest_sha256']),
            'status' => 'reserved',
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    public function finish(string $workspaceId, string $attemptId, string $status, ?int $costMinor): void
    {
        if (! in_array($status, ['complete', 'refused', 'incomplete', 'provider_failed', 'budget_denied'], true)
            || ($costMinor !== null && $costMinor < 0)) {
            throw new RuntimeException('Invalid AI trace result.');
        }

        $updated = DB::table('ai_gateway_traces')
            ->where('workspace_id', $workspaceId)->where('attempt_id', $attemptId)->where('status', 'reserved')
            ->update(['status' => $status, 'cost_minor' => $costMinor, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new RuntimeException('AI trace is missing or already finalized.');
        }
    }
}
