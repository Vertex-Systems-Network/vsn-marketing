<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\Company;
use App\Modules\Contacts\Domain\Contracts\CompanyRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final readonly class UpdateCompany
{
    public const AUDIT_ACTION = 'company.updated';

    public function __construct(
        private CompanyRepository $companies,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(TenantContext $context, string $companyId, string $name, ?string $domain = null): Company
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Company name cannot be empty.');
        }

        $domain = $this->normalizeDomain($domain);

        return $this->transaction->run(function () use ($context, $companyId, $name, $domain): Company {
            $company = $this->companies->update(
                workspaceId: $context->workspaceId,
                brandScopeId: $context->brandId,
                companyId: $companyId,
                name: $name,
                domain: $domain,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $company->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'company',
                subjectId: $company->id,
                evidence: ['fields' => ['name', 'domain']],
            );

            return $company;
        });
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = strtolower(rtrim(trim($domain), '.'));

        return $domain === '' ? null : $domain;
    }
}
