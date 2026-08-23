<?php

namespace App\Modules\Identity\Domain\Tenancy;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Workspace extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'name', 'slug'];
}
