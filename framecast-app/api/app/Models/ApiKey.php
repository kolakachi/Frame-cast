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
    protected $fillable = ['workspace_id', 'created_by_user_id', 'name', 'prefix', 'token_hash', 'expires_at', 'spend_cap_credits', 'rotated_from_id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime', 'expires_at' => 'datetime', 'spend_cap_credits' => 'integer'];
    }

    /**
     * wyv_live_<40 hex>. The prefix is the first 16 chars, enough to recognise
     * a key in a list. Expiry and spend cap are optional and carried over on
     * rotation.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(int $workspaceId, int $userId, string $name, ?\DateTimeInterface $expiresAt = null, ?int $spendCapCredits = null, ?int $rotatedFromId = null): array
    {
        $plain = 'wyv_live_'.bin2hex(random_bytes(20));

        $key = static::query()->create([
            'workspace_id'       => $workspaceId,
            'created_by_user_id' => $userId,
            'name'               => Str::limit(trim($name), 80, ''),
            'prefix'             => substr($plain, 0, 16),
            'token_hash'         => hash('sha256', $plain),
            'expires_at'         => $expiresAt,
            'spend_cap_credits'  => $spendCapCredits,
            'rotated_from_id'    => $rotatedFromId,
        ]);

        return [$key, $plain];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Net credits spent this calendar month by videos this key created.
     * Debits are positive and refunds negative in the ledger, so a sum is
     * the net figure. Attribution rides projects.api_key_id.
     */
    public function spentThisMonth(): int
    {
        if (\App\Services\Developer\OperationAccounting::enabled()) {
            return (int) CreditLedgerEntry::query()->whereIn('api_key_id', $this->accountingKeyIds())
                ->where('created_at', '>=', now()->startOfMonth())->sum('credits');
        }

        return (int) CreditLedgerEntry::query()
            ->whereIn('project_id', Project::query()->where('api_key_id', $this->getKey())->select('id'))
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('credits');
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

    /** Include predecessors so rotating a credential cannot reset its allowance. */
    public function accountingKeyIds(): array
    {
        $ids = [(int) $this->getKey()];
        $key = $this;
        while ($key->rotated_from_id && ! in_array((int) $key->rotated_from_id, $ids, true)) {
            $key = self::query()->where('workspace_id', $this->workspace_id)->find($key->rotated_from_id);
            if (! $key) {
                break;
            }
            $ids[] = (int) $key->getKey();
        }

        return $ids;
    }

    /** Whether a create for up to $credits would exceed the monthly cap. */
    public function wouldExceedCap(int $credits): bool
    {
        $reserved = \App\Services\Developer\OperationAccounting::enabled()
            ? (int) \Illuminate\Support\Facades\DB::table('api_operations')->whereIn('api_key_id', $this->accountingKeyIds())->sum('reserved_credits') : 0;

        return $this->spend_cap_credits !== null && $this->spentThisMonth() + $reserved + $credits > $this->spend_cap_credits;
    }

    public function maskedKey(): string
    {
        return $this->prefix.str_repeat('•', 8);
    }
}
