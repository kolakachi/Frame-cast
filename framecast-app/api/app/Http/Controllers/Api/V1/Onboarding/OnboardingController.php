<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\BrandFromSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Weave onboarding (2026-10-09): three skippable questions, then Create opens with a ready first brief. The
 * answers stay on the workspace (onboarding_json) for suggestions and defaults; the site becomes the brand kit.
 */
class OnboardingController extends Controller
{
    public const GOALS = ['ads', 'ugc', 'explain', 'launch', 'agency', 'explore'];
    public const SOURCES = ['tiktok', 'instagram', 'youtube', 'x', 'facebook', 'linkedin', 'google', 'appsumo', 'friend', 'assistant', 'other'];
    /** The examples Create shows first, by what the workspace makes most. */
    public const SAMPLE_FILTER = ['ads' => 'ads', 'ugc' => 'ugc', 'explain' => 'explainer', 'launch' => 'launch', 'agency' => 'ads', 'explore' => 'all'];

    public function brand(Request $r, BrandFromSite $site)
    {
        $this->owner($r);
        $in = $r->validate(['url' => 'nullable|string|max:300', 'name' => 'nullable|string|max:80']);
        abort_if(empty($in['url']) && empty($in['name']), 422, 'Paste your website or type your brand name.');
        return response()->json(['data' => ! empty($in['url']) ? $site->read($r->user(), $in['url']) : $site->named($r->user(), $in['name'])]);
    }

    /** Finishes (or skips) the onboarding: saves the answers, marks the user onboarded, returns the first brief. */
    public function finish(Request $r)
    {
        $this->owner($r);
        $in = $r->validate(['goal' => 'nullable|in:'.implode(',', self::GOALS), 'heard' => 'nullable|in:'.implode(',', self::SOURCES), 'industry' => 'nullable|string|max:20', 'skipped' => 'boolean']);
        $user = $r->user();
        $skipped = (bool) ($in['skipped'] ?? false);
        if (! empty($in['industry']) && isset(\App\Services\Create\Industry::LIST[$in['industry']]))
            DB::table('workspaces')->where('id', $user->workspace_id)->update(['industry' => $in['industry'], 'industry_source' => 'onboarding']);
        $answers = BrandFromSite::merge($user->workspace_id, array_filter(['goal' => $in['goal'] ?? null, 'heard' => $in['heard'] ?? null,
            ($skipped ? 'skipped_at' : 'done_at') => now()->toIso8601String()], fn ($v) => $v !== null));
        $user->forceFill(['preferences_json' => array_merge((array) ($user->preferences_json ?? []), ['onboarded' => true])])->save();
        $goal = $in['goal'] ?? null;
        return response()->json(['data' => [
            'brief' => $skipped || ! $goal ? null : self::brief($goal, (array) ($answers['site'] ?? []), DB::table('workspaces')->where('id', $user->workspace_id)->value('industry')),
            'style' => $skipped || ! $goal ? null : self::style($goal, DB::table('workspaces')->where('id', $user->workspace_id)->value('industry')),
            'sample_filter' => $goal ? self::SAMPLE_FILTER[$goal] : 'all',
        ]]);
    }

    /** The first video's brief, in the user's words to edit: it names the brand and links the site (Create reads it). */
    public static function brief(string $goal, array $site, ?string $industry = null): string
    {
        $brand = trim((string) ($site['name'] ?? ''));
        $product = trim((string) (($site['products'] ?? [])[0] ?? ''));
        $what = $product !== '' && $brand !== '' && ! str_contains(mb_strtolower($product), mb_strtolower($brand)) ? $product.' by '.$brand : ($product ?: ($brand ?: '[your product]'));
        $from = ! empty($site['url']) ? ', using the facts on '.$site['url'] : '';
        return match ($goal) {
            'ads' => "A 15-second vertical product ad for {$what}{$from}.",
            'ugc' => "A 20-second vertical creator-style ad: someone tries {$what} and says why they love it{$from}.",
            'explain' => "A 30-second vertical explainer of how {$what} works{$from}.",
            'launch' => "A 15-second vertical launch teaser for {$what}{$from}.",
            'agency' => $brand !== '' ? "A 15-second vertical product ad for my client {$brand}{$from}." : 'A 15-second vertical product ad for my client [client name and website].',
            default => "A 15-second vertical video about {$what}{$from}. Surprise me.",
        };
    }

    /** A style pack when the answers point to one; otherwise WyvStudio chooses per video. */
    public static function style(string $goal, ?string $industry): ?string
    {
        if ($goal === 'launch') return 'launch-reel';
        if ($goal === 'explain' && in_array($industry, ['tech', 'education'], true)) return 'data-story';
        if (in_array($goal, ['ads', 'ugc'], true) && $industry === 'fashion') return 'collage-zine';
        return null;
    }

    /** Only the workspace's own people answer for it (clients and collaborators never see onboarding). */
    private function owner(Request $r): void
    {
        abort_if(in_array($r->user()->role, ['client', 'client_editor', 'client_admin', 'collaborator'], true), 403);
    }
}
