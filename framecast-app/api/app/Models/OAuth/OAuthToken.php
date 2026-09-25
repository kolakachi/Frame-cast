<?php

namespace App\Models\OAuth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthToken extends Model
{
    protected $table = 'oauth_tokens';

    protected $fillable = ['grant_id', 'access_token_hash', 'refresh_token_hash', 'access_expires_at', 'refresh_expires_at', 'rotated_at'];

    protected function casts(): array
    {
        return ['access_expires_at' => 'datetime', 'refresh_expires_at' => 'datetime', 'rotated_at' => 'datetime'];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(OAuthGrant::class, 'grant_id');
    }
}
