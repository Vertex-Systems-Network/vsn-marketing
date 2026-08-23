<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Contacts\Domain\Contracts\TagRepository;
use App\Modules\Contacts\Domain\Tag;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final readonly class CreateTag
{
    public const AUDIT_ACTION = 'tag.created';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private TagRepository $tags,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(TenantContext $context, string $name): Tag
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Tag name is required.');
        }

        return $this->transaction->run(function () use ($context, $name): Tag {
            $tag = $this->tags->create(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                name: $name,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'tag',
                subjectId: $tag->id,
                evidence: ['name_present' => true],
            );

            return $tag;
        });
    }
}
