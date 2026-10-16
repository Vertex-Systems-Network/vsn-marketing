<?php

namespace App\Modules\Identity\Application\Governance;

use App\Modules\Identity\Domain\Governance\RegionalDataAdmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Workspace data map across every canonical and downstream surface, with fail-closed routing. */
final readonly class DataResidencyMap
{
    public function __construct(private RegionalDataAdmission $admission) {}

    public function regionFor(string $workspaceId): ?string
    {
        $region = DB::table('workspace_data_policies')->where('workspace_id', $workspaceId)->value('region');

        $default = config('residency.default_region');

        return is_string($region) ? $region : (is_string($default) ? $default : null);
    }

    /** @return array<string, array{destination: ?string, admitted: bool}> */
    public function map(string $workspaceId): array
    {
        $map = [];
        foreach (RegionalDataAdmission::SURFACES as $surface) {
            $destination = config('residency.surfaces.'.$surface);
            $destination = is_string($destination) && $destination !== '' ? $destination : null;
            $map[$surface] = [
                'destination' => $destination,
                'admitted' => $this->admits($workspaceId, $surface, $destination),
            ];
        }

        return $map;
    }

    /** Routing guard for any write or egress of workspace data. */
    public function assertRoutable(string $workspaceId, string $surface, ?string $destinationRegion = null): void
    {
        $destinationRegion ??= config('residency.surfaces.'.$surface);
        if (! $this->admits($workspaceId, $surface, is_string($destinationRegion) ? $destinationRegion : null)) {
            throw new AuthorizationException('Data routing denied by residency policy.');
        }
    }

    private function admits(string $workspaceId, string $surface, ?string $destination): bool
    {
        $supported = config('residency.supported_regions');

        return $this->admission->allows(is_array($supported) ? array_values($supported) : [],
            $this->regionFor($workspaceId), $surface, $destination);
    }
}
