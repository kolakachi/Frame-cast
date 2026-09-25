<?php

namespace App\Models\OAuth;

use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's approval of a connector for one workspace, backed by a hidden API key. */
class OAuthGrant extends Model
{
    protected $table = 'oauth_grants';

    protected $fillable = ['client_id', 'user_id', 'workspace_id', 'api_key_id', 'scopes', 'revoked_at'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'revoked_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'client_id');
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    /** Ends the grant and the key behind it, in one motion. */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
        ApiKey::query()->whereKey($this->api_key_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
