<?php

namespace App\Services\Agency;

use App\Models\User;
use App\Services\CreditService;
use Illuminate\Support\Facades\{Context, DB, Schema};

/**
 * A collaborator's monthly credit allowance (phase 3, 2026-10-09). A collaborator is an agency's team member: they
 * spend the agency's credits, in the agency and in the clients it gives them, up to the allowance the agency sets.
 * The person behind every request rides the request's hidden context into the jobs it starts, so each spend can be
 * put against them; a paid build reserves its whole quote up front, so it is checked once when it is approved and
 * never stops halfway.
 */
class Allowance
{
    public const CONTEXT = 'wyv_spender';

    /** The signed-in person behind the current request or job, if any. */
    public static function spenderId(): ?int
    {
        $id = Context::getHidden(self::CONTEXT);

        return $id ? (int) $id : null;
    }

    /** The allowance that binds this person: only a collaborator with one set. */
    public static function limitFor(int $userId): ?int
    {
        $u = DB::table('users')->where('id', $userId)->first(['role', 'monthly_credit_allowance']);

        return $u && $u->role === User::ROLE_COLLABORATOR && $u->monthly_credit_allowance !== null ? (int) $u->monthly_credit_allowance : null;
    }

    /** What this person has spent since the month began (refunds net off; top-ups and transfers never count). */
    public static function spentThisMonth(int $userId): int
    {
        $q = DB::table('credit_ledger')->where('user_id', $userId)->where('created_at', '>=', now()->startOfMonth());

        return max(0, (int) CreditService::onlySpend($q)->sum('credits'));
    }

    /** Credits still held for this person's unfinished paid work. */
    public static function reserved(int $userId): int
    {
        if (! self::operationsHaveUser()) return 0;

        return (int) DB::table('api_operations')->where('user_id', $userId)->whereIn('status', ['running', 'needs_attention'])->sum('reserved_credits');
    }

    public static function wouldExceed(int $userId, int $amount, bool $withReserved = false): bool
    {
        $limit = self::limitFor($userId);

        return $limit !== null && self::spentThisMonth($userId) + ($withReserved ? self::reserved($userId) : 0) + $amount > $limit;
    }

    /** The words a collaborator sees when a paid action would pass their allowance. */
    public static function message(int $userId): string
    {
        $limit = (int) self::limitFor($userId);
        $left = max(0, $limit - self::spentThisMonth($userId) - self::reserved($userId));

        return sprintf('That would pass your %s-credit allowance for this month (%s left). Ask your agency to raise it.', number_format($limit), number_format($left));
    }

    /** Older test and probe schemas build api_operations without the person column; real ones have it. Asked each
     *  time (once per paid approval), never cached across connections. */
    public static function operationsHaveUser(): bool
    {
        return Schema::hasColumn('api_operations', 'user_id');
    }
}
