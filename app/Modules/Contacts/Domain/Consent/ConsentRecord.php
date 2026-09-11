<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Domain\Consent;

use App\Modules\Contacts\Domain\Contact\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $contact_id
 * @property int $workspace_id
 * @property string $channel
 * @property string $purpose
 * @property string $source
 * @property string $decision
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property array $evidence
 * @property \Carbon\CarbonImmutable $created_at
 */
class ConsentRecord extends Model
{
    public const DECISION_GRANTED = 'granted';
    public const DECISION_DENIED = 'denied';
    public const DECISION_REVOKED = 'revoked';

    public $timestamps = false; // Append-only, no updated_at

    protected $fillable = [
        'contact_id',
        'workspace_id',
        'channel',
        'purpose',
        'source',
        'decision',
        'occurred_at',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (self $record) {
            // Enforce append-only: once created, records cannot be modified
            if ($record->exists) {
                throw new \RuntimeException('ConsentRecord is append-only and cannot be modified');
            }
        });

        static::updating(function (self $record) {
            throw new \RuntimeException('ConsentRecord is append-only and cannot be updated');
        });

        static::deleting(function (self $record) {
            throw new \RuntimeException('ConsentRecord is append-only and cannot be deleted');
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Workspace::class, 'workspace_id');
    }

    public function isGranted(): bool
    {
        return $this->decision === self::DECISION_GRANTED;
    }

    public function isDenied(): bool
    {
        return $this->decision === self::DECISION_DENIED || $this->decision === self::DECISION_REVOKED;
    }
}
