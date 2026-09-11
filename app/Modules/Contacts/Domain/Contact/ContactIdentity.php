<?php

namespace App\Modules\Contacts\Domain\Contact;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactIdentity model - stores provider-specific external IDs for a canonical Contact.
 * 
 * This model enables provider neutrality by keeping external IDs (Mailchimp, SendGrid, etc.)
 * separate from the canonical Contact. Multiple identities can exist for one contact,
 * each representing a different provider's view of the same person.
 */
final class ContactIdentity extends Model
{
    use HasUuids, HasFactory;

    protected $table = 'contact_identities';

    /**
     * @var array<string, string>
     */
    protected $fillable = [
        'contact_id',
        'provider_key', // e.g., 'mailchimp', 'sendgrid', 'hubspot'
        'external_id',  // The ID in the external provider's system
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get the canonical contact this identity belongs to.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Scope to find identity by provider and external ID.
     */
    public function scopeForProvider($query, string $providerKey, string $externalId)
    {
        return $query->where('provider_key', $providerKey)->where('external_id', $externalId);
    }

    /**
     * Scope to filter identities by provider key.
     */
    public function scopeByProvider($query, string $providerKey)
    {
        return $query->where('provider_key', $providerKey);
    }
}
