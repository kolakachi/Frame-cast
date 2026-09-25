<?php

namespace App\Models\OAuth;

use Illuminate\Database\Eloquent\Model;

/** A connector that registered itself. Public client: no secret. */
class OAuthClient extends Model
{
    protected $table = 'oauth_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'redirect_uris'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, (array) $this->redirect_uris, true);
    }
}
