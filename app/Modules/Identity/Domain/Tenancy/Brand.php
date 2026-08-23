<?php

namespace App\Modules\Identity\Domain\Tenancy;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Brand extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'name', 'slug'];
}
