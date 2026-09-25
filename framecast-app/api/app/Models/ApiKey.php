<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A long-lived credential for calling the API without a browser session.
 *
 * Only the hash is stored. The plaintext is returned once, at creation, and
 * cannot be recovered — a lost key is replaced, not looked up.
 */
class ApiKey extends Model
{
    protected $fillable = ['workspace_id', 'created_by_user_id', 'name', 'prefix', 'token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** wyv_live_<40 hex>. The prefix is the first 16 chars, enough to look up and to recognise. */
    public static function issue(int $workspaceId, int $userId, string $name): array
    {
        $plain = 'wyv_live_'.bin2hex(random_bytes(20));

        $key = static::query()->create([
            'workspace_id'       => $workspaceId,
            'created_by_user_id' => $userId,
            'name'               => Str::limit(trim($name), 80, ''),
            'prefix'             => substr($plain, 0, 16),
            'token_hash'         => hash('sha256', $plain),
        ]);

        return [$key, $plain];
    }

    /**
     * Resolve a presented token, or null.
     *
     * Looked up by the full hash, which is unique, rather than by the
     * 16-character prefix: two keys can share a prefix (28 bits of entropy),
     * and a prefix lookup that took the first row would lock the second key
     * out. The prefix is for recognising a key in a list, nothing more.
     */
    public static function resolve(string $plain): ?self
    {
        if (! str_starts_with($plain, 'wyv_live_')) {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->first();
    }

    public function maskedKey(): string
    {
        return $this->prefix.str_repeat('•', 8);
    }
}
