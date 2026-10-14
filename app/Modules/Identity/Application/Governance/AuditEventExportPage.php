<?php

namespace App\Modules\Identity\Application\Governance;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Bounded metadata export; raw audit evidence is never returned. */
final class AuditEventExportPage
{
    public function read(string $workspaceId, ?string $beforeId, int $limit): array
    {
        if ($workspaceId === '' || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid audit export boundary.');
        }

        $cursor = null;
        if ($beforeId !== null) {
            $cursor = DB::table('audit_events')->where('workspace_id', $workspaceId)
                ->where('id', $beforeId)->first(['id', 'occurred_at']);
            if ($cursor === null) {
                throw new NotFoundHttpException;
            }
        }

        $query = DB::table('audit_events')->where('workspace_id', $workspaceId);
        if ($cursor !== null) {
            $query->where(function ($query) use ($cursor): void {
                $query->where('occurred_at', '<', $cursor->occurred_at)
                    ->orWhere(function ($query) use ($cursor): void {
                        $query->where('occurred_at', $cursor->occurred_at)->where('id', '<', $cursor->id);
                    });
            });
        }

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($limit + 1)->get(['id', 'action', 'actor_id', 'subject_type', 'subject_id', 'occurred_at']);
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn ($row): array => [
            'id' => $row->id,
            'action' => $row->action,
            'actor_id' => $row->actor_id,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'occurred_at' => $row->occurred_at,
        ])->values()->all();

        return ['items' => $items, 'next_cursor' => $hasMore ? end($items)['id'] : null];
    }
}
