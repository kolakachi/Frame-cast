<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Action rewards — credits for behaviors that grow the business (publishing,
 * referrals), not idle logins. Every grant is idempotent: a unique row in
 * reward_grants gates it so a reward can never be paid twice, even under a
 * race. See spec/ACTION_REWARDS.md.
 */
class RewardService
{
    /** Credit reward per action. Bounded on purpose. */
    public const AMOUNTS = [
        'first_publish'      => 50,   // ~$0.23 COGS — once per workspace, rewards the distribution moment
        'referral_converted' => 200,  // ~$0.92 COGS — paid only when a referred workspace becomes paying
    ];

    public function __construct(private CreditService $credits)
    {
    }

    /**
     * Grant a one-time action reward. Idempotent on (action, workspace_id,
     * subject_id) — a duplicate insert is swallowed and returns false without
     * granting. subject_id defaults to 0 for "self" actions; pass the
     * triggering entity id (e.g. referred workspace) for per-subject rewards.
     *
     * @return bool true if credits were granted this call, false if already rewarded / unknown action
     */
    public function grant(int $workspaceId, string $action, int $subjectId = 0): bool
    {
        $amount = self::AMOUNTS[$action] ?? 0;
        if ($amount <= 0) {
            return false;
        }

        // Claim the reward slot atomically. The unique index is the gate:
        // if the row already exists, the insert throws and we bail without
        // crediting — that's the idempotency guarantee.
        try {
            DB::table('reward_grants')->insert([
                'workspace_id' => $workspaceId,
                'action'       => $action,
                'subject_id'   => $subjectId,
                'amount'       => $amount,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false; // already rewarded
        } catch (\Throwable) {
            return false; // never let a reward-bookkeeping failure break the caller
        }

        $this->credits->grant($workspaceId, $amount, "reward:{$action}");

        return true;
    }

    /**
     * Reward the referrer when a referred workspace first becomes paying.
     * No-op when the workspace wasn't referred. Idempotent per (referrer,
     * referred) so it can only pay once per referred account.
     */
    public function referralConversion(Workspace $referred): void
    {
        $referrerId = (int) ($referred->referred_by_workspace_id ?? 0);
        if ($referrerId <= 0 || $referrerId === (int) $referred->getKey()) {
            return;
        }
        $this->grant($referrerId, 'referral_converted', (int) $referred->getKey());
    }

    /**
     * Ensure a workspace has a shareable referral code, generating a unique
     * one lazily. Returns the code.
     */
    public function ensureReferralCode(Workspace $workspace): string
    {
        if (! empty($workspace->referral_code)) {
            return $workspace->referral_code;
        }

        do {
            $code = strtolower(Str::random(8));
        } while (Workspace::query()->where('referral_code', $code)->exists());

        $workspace->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /**
     * Resolve a referral code to the referrer workspace id, or null. Used at
     * signup to attribute a new workspace to its referrer.
     */
    public function referrerIdForCode(?string $code): ?int
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        $id = Workspace::query()->where('referral_code', strtolower($code))->value('id');

        return $id ? (int) $id : null;
    }
}
