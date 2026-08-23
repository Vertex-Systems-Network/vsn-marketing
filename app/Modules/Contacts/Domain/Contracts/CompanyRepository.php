<?php

namespace App\Modules\Contacts\Domain\Contracts;

use App\Modules\Contacts\Domain\Company;

interface CompanyRepository
{
    public function create(
        string $id,
        string $workspaceId,
        ?string $brandId,
        string $name,
        ?string $domain,
    ): Company;

    public function update(
        string $workspaceId,
        ?string $brandScopeId,
        string $companyId,
        string $name,
        ?string $domain,
    ): Company;

    public function find(string $workspaceId, ?string $brandScopeId, string $companyId): ?Company;
}
