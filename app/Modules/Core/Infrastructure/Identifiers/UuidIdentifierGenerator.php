<?php

namespace App\Modules\Core\Infrastructure\Identifiers;

use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use Illuminate\Support\Str;

final class UuidIdentifierGenerator implements IdentifierGenerator
{
    public function next(): string
    {
        return Str::uuid()->toString();
    }
}
