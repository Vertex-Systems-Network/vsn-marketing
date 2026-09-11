<?php

namespace App\Modules\Contacts\Domain\Company;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Identity\Domain\Tenancy\Workspace;

/**
 * Company model - represents a B2B company/account within a workspace.
 * 
 * Companies can have multiple contacts associated with them.
 * Like Contact, this is workspace-scoped for isolation.
 */
final class Company extends Model
{
    use HasUuids, HasFactory;

    protected $table = 'companies';

    /**
     * @var array<string, string>
     */
    protected $fillable = [
        'workspace_id',
        'name',
        'domain',
        'website',
        'industry',
        'employee_count',
        'annual_revenue',
        'billing_address',
        'shipping_address',
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'billing_address' => 'array',
        'shipping_address' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Get the workspace this company belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Scope to filter companies by workspace (enforces isolation).
     */
    public function scopeForWorkspace($query, Workspace $workspace)
    {
        return $query->where('workspace_id', $workspace->id);
    }

    /**
     * Normalize domain for deterministic uniqueness.
     */
    public static function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }
}
