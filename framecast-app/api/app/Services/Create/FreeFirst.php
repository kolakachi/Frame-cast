<?php

namespace App\Services\Create;

use App\Models\Workspace;
use App\Services\WorkspaceUsageService;
use Illuminate\Support\Facades\DB;

/**
 * The free first ad (FREE-FIRST, owner 2026-10-17; docs/product/first-fix-plan.md §4): a free plan makes one ad, from a
 * template, as a free swap (the template's words, colours and photos changed; nothing bought, no builder). Its planning
 * is not charged, the quote is allowed through the free-plan gate only when it is a free swap, and the video is
 * watermarked ("Made with WyvStudio") and can be downloaded. The one conversation is the first template conversation
 * the free plan starts (claim), kept on the workspace: starting another does not make another free ad.
 */
class FreeFirst
{
    public const GATE = 'Your free first ad is made from a template: it changes the words, colours and photos. New shots, voices and presenters, and ads without the WyvStudio mark, come with the $9 Test Pass or a pack.';

    /** Whether the workspace's plan watermarks its videos (a free plan). */
    public static function freePlan(int $workspaceId): bool
    {
        $tier = Workspace::whereKey($workspaceId)->value('plan_tier');
        return (bool) (WorkspaceUsageService::plans()[$tier]['watermark'] ?? true);
    }

    /** The workspace's free first ad: the template conversation it claimed (claim), kept on the workspace. */
    public static function conversationId(int $workspaceId): ?string
    {
        if (! self::kept()) return null;
        $kept = json_decode((string) DB::table('workspaces')->where('id', $workspaceId)->value('onboarding_json'), true) ?: [];
        return is_string($kept['free_first_conversation_id'] ?? null) ? $kept['free_first_conversation_id'] : null;
    }

    /** A free plan's first template conversation becomes its free first ad, once (a lock settles two at the same moment). */
    public static function claim(int $workspaceId, string $conversationId): void
    {
        if (! self::freePlan($workspaceId) || ! self::kept()) return;
        DB::transaction(function () use ($workspaceId, $conversationId) {
            $row = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first(['onboarding_json']);
            $kept = json_decode((string) ($row->onboarding_json ?? ''), true) ?: [];
            if (! empty($kept['free_first_conversation_id'])) return;
            DB::table('workspaces')->where('id', $workspaceId)->update(['onboarding_json' => json_encode([...$kept, 'free_first_conversation_id' => $conversationId])]);
        });
    }

    /** Whether this conversation is a free plan's free first ad. */
    public static function applies(int $workspaceId, string $conversationId): bool
    {
        return self::freePlan($workspaceId) && self::conversationId($workspaceId) === $conversationId;
    }

    /** Whether the workspace can keep it (the column every install has since onboarding; not every test schema does). */
    private static function kept(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('workspaces', 'onboarding_json');
    }
}
