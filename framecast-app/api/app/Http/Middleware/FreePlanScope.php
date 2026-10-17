<?php

namespace App\Http\Middleware;

use App\Services\Create\FreeFirst;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A free account makes its one free ad and can upgrade; nothing else (owner, 2026-10-17). Reading stays open, so past
 * videos can still be watched and downloaded. Making, changing or publishing anything outside Create is refused with
 * the upgrade message; Create has its own free-ad rules (FreeFirst). Billing, sign-in, settings, onboarding and
 * feedback stay open. Staff are never limited.
 */
class FreePlanScope
{
    public const MESSAGE = 'Your free account makes one free ad from a template. Upgrade to make more: the $9 Test Pass or a pack.';

    /** First path segments under /api/v1 that make, change or publish something, refused for free plans. */
    private const BLOCKED = ['api-keys', 'approvals', 'assets', 'caption-presets', 'channels', 'characters', 'client-work', 'collections',
        'cruise', 'daily-streak', 'localization-links', 'projects', 'scenes', 'scheduled-posts', 'series', 'sfx', 'social', 'team', 'ugc',
        'variant-sets', 'voice-profiles', 'workspace-access'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) return $next($request);
        $user = $request->user();
        if (! $user || in_array($user->role, ['super_admin', 'platform_admin'], true) || ! $user->workspace_id) return $next($request);
        $parts = explode('/', trim($request->path(), '/'));
        // /api/v1/<segment>/… for the app; /api/developer/v1/<segment>/… for the API and connector, where only Create
        // ("weave") makes anything for a free plan.
        $developer = ($parts[1] ?? '') === 'developer';
        $segment = $developer ? ($parts[3] ?? '') : ($parts[2] ?? '');
        $blocked = $developer ? ! in_array($segment, ['weave', 'oauth'], true) : in_array($segment, self::BLOCKED, true);
        if ($blocked && FreeFirst::freePlan((int) $user->workspace_id)) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'free_plan', 'upgrade_url' => '/continue?pass=1'], 402);
        }

        return $next($request);
    }
}
