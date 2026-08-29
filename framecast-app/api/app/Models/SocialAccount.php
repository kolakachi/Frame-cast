<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected static function booted(): void
    {
        static::created(function (SocialAccount $account): void {
            // No per-user column on this table — attribute to the workspace
            // owner, which for every current workspace is the only user.
            $ownerId = \App\Models\User::where('workspace_id', $account->workspace_id)->orderBy('id')->value('id');
            \App\Services\Analytics\PostHogService::capture(
                $ownerId ? (int) $ownerId : null,
                'social_account_connected',
                ['platform' => $account->platform ?? $account->provider ?? null],
                $account->workspace_id,
            );
        });
    }

    protected $fillable = [
        'workspace_id',
        'platform',
        'platform_user_id',
        'platform_username',
        'platform_display_name',
        'platform_avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'scopes',
        'platform_meta',
    ];

    protected function casts(): array
    {
        return [
            'scopes'           => 'array',
            'platform_meta'    => 'array',
            'token_expires_at' => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
