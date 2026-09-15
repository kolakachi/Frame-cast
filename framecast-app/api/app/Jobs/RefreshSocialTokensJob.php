<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\Publishing\PlatformAdapterFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Keep connected accounts connected, and find out early when one is not.
 *
 * Tokens were only ever refreshed inside PublishVideoJob, at the moment of
 * posting. That works — a six-week-stale YouTube token refreshed fine — but it
 * has two costs.
 *
 * The connection's `status` stayed 'active' whatever the token said, so
 * Settings showed five expired accounts as connected and nobody could tell.
 * And a revoked refresh token was only discovered when a scheduled post
 * failed, which is the worst possible moment: the post is already late, and
 * the person finds out from a failure rather than from a prompt.
 *
 * Refreshing ahead of expiry moves that discovery to a quiet hour and lets the
 * UI tell the truth in between.
 */
class RefreshSocialTokensJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    /**
     * Refresh anything expiring within this window.
     *
     * Wider than the hourly cadence on purpose: Google's access tokens last an
     * hour exactly, so a tick that only caught already-expired ones would
     * always be racing the clock.
     */
    private const LOOKAHEAD_HOURS = 2;

    public function uniqueId(): string
    {
        return 'refresh-social-tokens';
    }

    public function handle(): void
    {
        $due = SocialAccount::query()
            ->whereNotNull('refresh_token')
            // An account already marked expired stays that way until the owner
            // reconnects: retrying a refresh token we know is dead, hourly,
            // forever, just makes noise at the provider.
            ->where('status', '!=', 'expired')
            ->where(function ($q) {
                $q->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '<=', now()->addHours(self::LOOKAHEAD_HOURS));
            })
            ->get();

        $refreshed = 0;
        $failed = 0;

        foreach ($due as $account) {
            try {
                PlatformAdapterFactory::make($account->platform)->refreshToken($account);
                $refreshed++;
            } catch (\Throwable $e) {
                $failed++;
                // The refresh token itself is gone — revoked, or the user
                // removed the app. Only reconnecting fixes this, so say so
                // rather than trying again next hour.
                $account->forceFill(['status' => 'expired'])->save();
                Log::warning('RefreshSocialTokensJob: account needs reconnecting', [
                    'social_account_id' => $account->getKey(),
                    'workspace_id' => $account->workspace_id,
                    'platform' => $account->platform,
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]);
            }
        }

        if ($refreshed || $failed) {
            Log::info('RefreshSocialTokensJob finished', [
                'considered' => $due->count(), 'refreshed' => $refreshed, 'expired' => $failed,
            ]);
        }
    }
}
