<?php

namespace App\Modules\Contacts\Infrastructure;

use App\Modules\Contacts\Domain\Company;
use App\Modules\Contacts\Domain\Contracts\CompanyRepository;
use App\Modules\Core\Domain\Contracts\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseCompanyRepository implements CompanyRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function create(
        string $id,
        string $workspaceId,
        ?string $brandId,
        string $name,
        ?string $domain,
    ): Company {
        $this->assertBrandInWorkspace($workspaceId, $brandId);
        $now = $this->clock->now();

        $this->database->connection()->table('companies')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'brand_id' => $brandId,
            'name' => $name,
            'domain' => $domain,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new Company($id, $workspaceId, $brandId, $name, $domain);
    }

    public function update(
        string $workspaceId,
        ?string $brandScopeId,
        string $companyId,
        string $name,
        ?string $domain,
    ): Company {
        $company = $this->find($workspaceId, $brandScopeId, $companyId);
        if ($company === null) {
            throw new AuthorizationException('Company access denied.');
        }

        $query = $this->database->connection()->table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('id', $companyId);

        if ($brandScopeId !== null) {
            $query->where('brand_id', $brandScopeId);
        }

        $query->update([
            'name' => $name,
            'domain' => $domain,
            'updated_at' => $this->clock->now(),
        ]);

        return new Company(
            id: $company->id,
            workspaceId: $company->workspaceId,
            brandId: $company->brandId,
            name: $name,
            domain: $domain,
        );
    }

    public function find(string $workspaceId, ?string $brandScopeId, string $companyId): ?Company
    {
        $query = $this->database->connection()->table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('id', $companyId);

        if ($brandScopeId !== null) {
            $query->where('brand_id', $brandScopeId);
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function assertBrandInWorkspace(string $workspaceId, ?string $brandId): void
    {
        if ($brandId === null) {
            return;
        }

        $exists = $this->database->connection()->table('brands')
            ->where('id', $brandId)
            ->where('workspace_id', $workspaceId)
            ->exists();

        if (! $exists) {
            throw new AuthorizationException('Brand access denied.');
        }
    }

    private function hydrate(stdClass $row): Company
    {
        return new Company(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            brandId: $row->brand_id === null ? null : (string) $row->brand_id,
            name: (string) $row->name,
            domain: $row->domain === null ? null : (string) $row->domain,
        );
    }
}
