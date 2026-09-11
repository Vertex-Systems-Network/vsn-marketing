<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Domain\Tag;

use App\Modules\Contacts\Domain\Contact\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $color
 * @property array $metadata
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Tag extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'workspace_id',
        'name',
        'color',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Workspace::class, 'workspace_id');
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag_assignments')
            ->withTimestamps()
            ->as('assignment');
    }
}
