<?php

namespace App\Models\OAuth;

use Illuminate\Database\Eloquent\Model;

class OAuthAuthorizationCode extends Model
{
    protected $table = 'oauth_authorization_codes';

    protected $fillable = ['code_hash', 'client_id', 'grant_id', 'code_challenge', 'redirect_uri', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
