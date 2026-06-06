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
        'name',
        'owner_user_id',
        'plan_tier',
        'plan_status',
        'plan_renews_at',
        'paddle_customer_id',
        'paddle_subscription_id',
        'fastspring_account_id',
        'fastspring_subscription_id',
        'status',
        'credits_monthly',
        'credits_topup',
        'credits_free_granted',
        'billing_renews_at',
        'daily_streak_count',
        'daily_streak_last_claim_at',
        'cruise_auto_apply',
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
    ];

    public function creditsBalance(): int
    {
        return max(0, (int) $this->credits_monthly + (int) $this->credits_topup);
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
