<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\Company;
use App\Modules\Contacts\Domain\Contracts\CompanyRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final readonly class CreateCompany
{
    public const AUDIT_ACTION = 'company.created';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private CompanyRepository $companies,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(TenantContext $context, string $name, ?string $domain = null): Company
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Company name cannot be empty.');
        }

        $domain = $this->normalizeDomain($domain);

        return $this->transaction->run(function () use ($context, $name, $domain): Company {
            $company = $this->companies->create(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                name: $name,
                domain: $domain,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'company',
                subjectId: $company->id,
                evidence: ['fields' => array_values(array_filter(['name', $domain !== null ? 'domain' : null]))],
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
