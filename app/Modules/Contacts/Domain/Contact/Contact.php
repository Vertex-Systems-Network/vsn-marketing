<?php

namespace App\Modules\Contacts\Domain\Contact;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Identity\Domain\Tenancy\Workspace;

/**
 * Canonical Contact model - provider-neutral, workspace-scoped customer identity.
 * 
 * This is the primary entity for representing a contact (person) within a workspace.
 * All external provider IDs (Mailchimp, SendGrid, etc.) are stored in ContactIdentity,
 * not as primary keys here. This ensures provider neutrality.
 */
final class Contact extends Model
{
    use HasUuids, HasFactory;

    protected $table = 'contacts';

    /**
     * @var array<string, string>
     */
    protected $fillable = [
        'workspace_id',
        'primary_email',
        'primary_phone',
        'first_name',
        'last_name',
        'display_name',
        'status', // 'active', 'subscribed', 'unsubscribed', 'bounced', 'complained'
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the workspace this contact belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get all identities for this contact (provider-specific IDs).
     */
    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    /**
     * Scope to filter contacts by workspace (enforces isolation).
     */
    public function scopeForWorkspace($query, Workspace $workspace)
    {
        return $query->where('workspace_id', $workspace->id);
    }

    /**
     * Normalize email for deterministic uniqueness.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Check if contact is in a subscribable state.
     */
    public function isSubscribable(): bool
    {
        return in_array($this->status, ['active', 'subscribed'], true);
    }
}
