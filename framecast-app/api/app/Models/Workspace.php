<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Channel;
use App\Models\BrandKit;
use App\Models\VoiceProfile;
use App\Models\CaptionPreset;
use App\Models\Template;
use App\Models\Project;

class Workspace extends Model
{
    use HasFactory;

    protected $fillable = [
        // Which affiliate sent this customer. Kept as the code rather than a
        // foreign key: it is a historical fact and must outlive the affiliate
        // row being removed.
        'affiliate_code',
        'affiliate_attributed_at',
        'name',
        'owner_user_id',
        'plan_tier',
        'plan_status',
        'plan_renews_at',
        'kelviq_account_id',
        'kelviq_subscription_id',
        'status',
        'credits_monthly',
        'credits_topup',
        'monthly_credit_cap',
        'funding_mode',
        'credits_free_granted',
        'billing_renews_at',
        'daily_streak_count',
        'daily_streak_last_claim_at',
        'cruise_auto_apply',
        'cruise_image_model',
        'cruise_animation_tier',
        'cruise_visual_source',
        'referral_code',
        'referred_by_workspace_id',
        'pending_checkout_plan',
        'pending_checkout_at',
        'pending_checkout_reminded_at',
    ];

    protected $casts = [
        'plan_renews_at'    => 'datetime',
        'billing_renews_at' => 'datetime',
        'credits_monthly'   => 'integer',
        'credits_topup'     => 'integer',
        'credits_free_granted' => 'integer',
        'daily_streak_count' => 'integer',
        'daily_streak_last_claim_at' => 'datetime',
        'cruise_auto_apply' => 'boolean',
        'pending_checkout_at' => 'datetime',
        'pending_checkout_reminded_at' => 'datetime',
    ];

    public function creditsBalance(): int
    {
        return max(0, (int) $this->credits_monthly + (int) $this->credits_topup);
    }

    /** The agency this client workspace belongs to, if it is one. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_workspace_id');
    }

    /** Client workspaces this agency owns. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_workspace_id');
    }

    /**
     * Whose credits this workspace spends.
     *
     * A client workspace has no balance of its own — the agency bought the
     * credits and every child draws on that one pool. Returning the parent here
     * means the whole credit path stays unaware that sub-accounts exist.
     */
    public const FUNDING_POOLED = 'pooled';
    public const FUNDING_FUNDED = 'funded';

    /**
     * A client the agency has handed its own credits to.
     *
     * Funded and pooled differ in one thing only — which workspace a charge
     * resolves to — so a funded client keeps its balance in credits_topup like
     * anybody else and every existing query still works on it.
     */
    public function isFunded(): bool
    {
        return $this->parent_workspace_id && $this->funding_mode === self::FUNDING_FUNDED;
    }

    /**
     * Whose balance pays for this workspace's work.
     *
     * A pooled client resolves up to its agency. A funded one stops here: its
     * allocation is the limit, and falling back to the agency would make the
     * allocation a suggestion rather than a budget.
     */
    public function creditRoot(): self
    {
        if ($this->isFunded()) {
            return $this;
        }

        return $this->parent_workspace_id ? ($this->parent ?? $this) : $this;
    }

    public function creditRootId(): int
    {
        if ($this->isFunded()) {
            return (int) $this->getKey();
        }

        return (int) ($this->parent_workspace_id ?: $this->getKey());
    }

    /** Tiers that may own client workspaces. */
    public function canOwnClients(): bool
    {
        return in_array((string) $this->plan_tier, (array) config('workspaces.client_tiers', []), true)
            && ! $this->parent_workspace_id;   // a client cannot have clients
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function brandKits(): HasMany
    {
        return $this->hasMany(BrandKit::class);
    }

    public function voiceProfiles(): HasMany
    {
        return $this->hasMany(VoiceProfile::class);
    }

    public function captionPresets(): HasMany
    {
        return $this->hasMany(CaptionPreset::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
