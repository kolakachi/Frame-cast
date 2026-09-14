<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A portal session.
 *
 * Only the hash is stored, so a leaked database does not hand over live
 * sessions, and the row's existence is what keeps the session alive — ending
 * an affiliate relationship ends their access, which a self-contained token
 * could not guarantee.
 */
class AffiliateSession extends Model
{
    protected $fillable = ['affiliate_id', 'token_hash', 'expires_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return array{0: string, 1: self} the plaintext token, and its row */
    public static function issue(Affiliate $affiliate, int $days): array
    {
        // Opportunistic cleanup: these expire on their own and nothing else
        // would ever remove them.
        static::query()->where('expires_at', '<', now()->subDay())->delete();

        $token = bin2hex(random_bytes(32));

        return [$token, static::query()->create([
            'affiliate_id' => $affiliate->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays($days),
        ])];
    }

    public static function resolve(string $token): ?Affiliate
    {
        if ($token === '') {
            return null;
        }

        $session = static::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        // A paused affiliate keeps their row but loses the dashboard: whatever
        // ended the relationship should end the access with it.
        $affiliate = $session?->affiliate;
        if (! $affiliate || $affiliate->status !== 'active') {
            return null;
        }

        // Written at most hourly — every request would be a write per read.
        if (! $session->last_seen_at || $session->last_seen_at->lt(now()->subHour())) {
            $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $affiliate;
    }

    public static function revoke(string $token): void
    {
        if ($token !== '') {
            static::query()->where('token_hash', hash('sha256', $token))->delete();
        }
    }
}
