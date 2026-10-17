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
    /** AI-planned changes in the free ad (owner, 2026-10-17): direct text and colour edits stay free past this. */
    public const PLAN_LIMIT = 5;

    public const PLAN_LIMIT_MESSAGE = 'Your free ad has used its 5 AI changes. You can still edit its text and colours for free with Edit; for more changes, new shots, voices and presenters, get the $9 Test Pass.';

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

    /**
     * The shape and length a template copy was made at (its first version's settings). A swap changes words, pictures
     * and the voice inside that picture, never its shape or length (owner's free-ad test, 2026-10-17: a "15-second
     * vertical" brief set 9:16 on a square 20 s template and the render failed its size check).
     */
    public static function copiedShape(string $conversationId): ?array
    {
        $first = DB::table('composition_revisions')->where('conversation_id', $conversationId)->where('number', 1)->value('metadata_json');
        $meta = json_decode((string) $first, true) ?: [];
        if (empty($meta['template_id'])) return null;
        $settings = (array) ($meta['settings'] ?? []);
        $template = DB::table('create_templates')->where('id', $meta['template_id'])->first(['shape', 'seconds']);
        $ratio = $settings['aspect_ratio'] ?? $template?->shape;
        $seconds = $settings['duration_seconds'] ?? ($template ? max(5, min(30, (int) $template->seconds)) : null);
        return $ratio && $seconds ? ['aspect_ratio' => (string) $ratio, 'duration_seconds' => (int) $seconds] : null;
    }

    /** "square, 20 seconds": how the conversation names a fixed shape. */
    public static function describeShape(array $shape): string
    {
        $name = match ($shape['aspect_ratio']) { '1:1' => 'square', '16:9' => 'landscape', '4:5' => '4:5', default => 'vertical' };
        return $name.', '.$shape['duration_seconds'].' seconds';
    }

    public const SHAPE_KEPT = 'Your free ad keeps the template\'s shape and length (%s): changing its words, pictures and voice doesn\'t change the layout. A paid plan can rebuild it in another shape.';

    /** Whether the workspace can keep it (the column every install has since onboarding; not every test schema does). */
    private static function kept(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('workspaces', 'onboarding_json');
    }

    /** How many AI plans the free ad has had (each planner call makes one). */
    public static function plansUsed(string $conversationId): int
    {
        return DB::table('create_plans')->where('conversation_id', $conversationId)->count();
    }

    /** Clips a model made: a template built on them is not free (owner, 2026-10-17: they involve generating AI video). */
    public const AI_VIDEO = ['ugc_take', 'generated_shot', 'animate_image', 'cutout_video', 'talking_photo', 'lip_sync', 'restyle'];

    /** Whether a template can be a free first ad: it has its own code and no AI-made video in it. */
    public static function templateFits(object $t): bool
    {
        if (($t->source ?? null) !== 'version' || empty($t->revision_id)) return false;
        $parts = json_decode((string) ($t->parts_json ?? ''), true) ?: [];
        foreach ((array) ($parts['clips'] ?? []) as $clip) if (in_array($clip['kind'] ?? '', self::AI_VIDEO, true)) return false;
        // Footage of the template's own product has to be replaced with the user's, which a free swap cannot do.
        if (! empty($parts['footage'])) return false;
        // Text the swap cannot reach: fields the code never reads, or WyvStudio's own name written into the code.
        if (! empty($parts['dead_fields'])) return false;
        // Words written into the code show on every copy whatever the fields say, so only audited templates are free
        // (the code scan for WyvStudio's name is a hint, not proof: one template passed with it in the code).
        $audited = config('create.free_templates');
        if (is_array($audited) && ! in_array($t->id ?? null, $audited, true)) return false;

        return true;
    }
}
