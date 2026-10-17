<?php

namespace App\Services\Analytics;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where an account came from (owner, 2026-10-11, priorities #6). The first visit is captured in the browser (Google
 * click id, UTM tags, referring site, landing page; marketing/attribution.js and web/src/services/attribution.js),
 * sent with the sign-up and kept on the workspace with one channel read from it. Each sale takes its account's channel
 * (RevenueService), so the Google campaign can be judged on money, not clicks.
 */
class Acquisition
{
    /** The first-visit cookie, shared by wyvstudio.com and the app; only set after "Allow" in the cookie notice. */
    public const COOKIE = 'wyv_src';

    public const CHANNELS = ['appsumo', 'affiliate', 'google_ads', 'referral', 'paid_social', 'email', 'outreach', 'community', 'listing', 'ai', 'search', 'social', 'other_site', 'direct', 'unknown'];

    private const KEYS = ['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ref_host', 'landing', 'at'];

    private const PAID = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paidsocial', 'paid_social', 'paid-social', 'ads', 'ad', 'display', 'cpm'];

    private const SEARCH = ['google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.', 'yahoo.com', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'startpage.com', 'qwant.com'];

    /** Checked before search: gemini.google.com is an assistant, not a search engine. */
    private const AI = ['chatgpt.com', 'chat.openai.com', 'openai.com', 'claude.ai', 'perplexity.ai', 'gemini.google.com', 'bard.google.com', 'copilot.microsoft.com', 'you.com', 'poe.com', 'phind.com', 'meta.ai', 'grok.com', 'deepseek.com', 'chatgpt', 'openai', 'claude', 'perplexity', 'gemini', 'copilot'];

    private const SOCIAL = ['facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'tiktok.com', 'x.com', 't.co', 'twitter.com', 'linkedin.com', 'lnkd.in', 'youtube.com', 'youtu.be', 'reddit.com', 'pinterest.com', 'threads.net', 'snapchat.com', 'facebook', 'fb', 'meta', 'instagram', 'ig', 'tiktok', 'twitter', 'linkedin', 'youtube', 'reddit', 'pinterest', 'threads', 'snapchat'];

    /** Directories and registries we are listed in (CMO-3, 2026-10-17): a listing, not a search or a social post. */
    private const LISTING = ['g2.com', 'capterra.com', 'getapp.com', 'softwareadvice.com', 'saashub.com', 'alternativeto.net', 'futuretools.io', 'producthunt.com', 'registry.modelcontextprotocol.io', 'theresanaiforthat.com', 'futurepedia.io', 'toolify.ai', 'g2', 'capterra', 'saashub', 'alternativeto', 'futuretools', 'producthunt'];

    /** Hosts a visitor passes through on the way in that say nothing about where they came from. */
    private const OURS = ['wyvstudio.com', 'kelviq.com', 'stripe.com', 'paypal.com', 'accounts.google.com', 'appsumo.com', 'localhost'];

    /**
     * The first visit for a sign-up: what the browser sent, else the shared cookie; Google's own click cookie
     * (_gcl_aw, set only after "Allow") fills in a click id neither carried. Every value is cut to size and cleaned.
     */
    public static function fromRequest(Request $request, mixed $sent = null): array
    {
        $touch = is_array($sent) ? $sent : [];
        if (! $touch) {
            $raw = (string) $request->cookie(self::COOKIE, '');
            $cookie = $raw !== '' ? json_decode(rawurldecode($raw), true) : null;
            if (is_array($cookie)) $touch = $cookie;
        }
        $touch = self::clean($touch);
        foreach (['_gcl_aw' => 'gclid', '_gcl_gb' => 'gbraid'] as $name => $key) {
            if (! empty($touch[$key])) continue;
            // GCL.<timestamp>.<click id>
            if (preg_match('/^GCL\.\d+\.([A-Za-z0-9_-]{10,200})$/', (string) $request->cookie($name, ''), $m)) $touch[$key] = $m[1];
        }

        return $touch;
    }

    public static function clean(array $touch): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $v = $touch[$k] ?? null;
            if (! is_string($v) || ($v = trim($v)) === '') continue;
            $v = match ($k) {
                'gclid', 'gbraid', 'wbraid' => preg_match('/^[A-Za-z0-9_-]{10,200}$/', $v) ? $v : null,
                'ref_host' => preg_match('/^[a-z0-9.-]{1,120}$/i', $v) ? strtolower(preg_replace('/^www\./i', '', $v)) : null,
                'landing' => str_starts_with($v, '/') ? mb_substr($v, 0, 200) : null,
                'at' => strtotime($v) ? date('c', strtotime($v)) : null,
                default => mb_substr(strip_tags($v), 0, 120),
            };
            if ($v !== null && $v !== '') $out[$k] = $v;
        }
        if (isset($out['ref_host']) && self::matches($out['ref_host'], self::OURS)) unset($out['ref_host']);

        return $out;
    }

    /** The one channel for a first visit, given what the account already carries (licence, affiliate, referral). */
    public static function channel(array $touch, ?Workspace $workspace = null): string
    {
        if ($workspace && DB::table('appsumo_licenses')->where('workspace_id', $workspace->getKey())->exists()) return 'appsumo';
        if ($workspace && ! empty($workspace->affiliate_code)) return 'affiliate';
        $source = strtolower((string) ($touch['utm_source'] ?? ''));
        $medium = strtolower((string) ($touch['utm_medium'] ?? ''));
        $paid = in_array($medium, self::PAID, true);
        if (! empty($touch['gclid']) || ! empty($touch['gbraid']) || ! empty($touch['wbraid'])
            || ($paid && in_array($source, ['google', 'adwords', 'google_ads', 'googleads', 'youtube'], true))) return 'google_ads';
        if ($workspace && $workspace->referred_by_workspace_id) return 'referral';
        if ($paid && self::matches($source, self::SOCIAL)) return 'paid_social';
        if ($medium === 'email' || $medium === 'newsletter') return 'email';
        // The two-week plan's own tests (cmo-plan.md): a message we sent one person, an answer in a community, a listing.
        if ($medium === 'outreach') return 'outreach';
        if ($medium === 'community') return 'community';
        $host = (string) ($touch['ref_host'] ?? '');
        if ($medium === 'listing') return 'listing';
        foreach (array_filter([$source, $host]) as $where) {
            if (self::matches($where, self::LISTING)) return 'listing';
        }
        foreach (array_filter([$source, $host]) as $where) {
            if (self::matches($where, self::AI)) return 'ai';
        }
        foreach (array_filter([$source, $host]) as $where) {
            if (self::matches($where, self::SOCIAL)) return 'social';
            if (self::matches($where, self::SEARCH) || in_array($where, ['google', 'bing', 'duckduckgo'], true)) return 'search';
        }

        // Nothing sent at all (an old page, an API client) is not the same as arriving with no referrer.
        return $source !== '' || $host !== '' ? 'other_site' : ($touch ? 'direct' : 'unknown');
    }

    /** Kept on a new account, once: a later visit never moves where an account came from. */
    public static function record(Workspace $workspace, array $touch): void
    {
        if ($workspace->acquisition_channel) return;
        $workspace->forceFill(['acquisition_json' => $touch ?: null, 'acquisition_channel' => self::channel($touch, $workspace)])->save();
    }

    /** A host or a source word against a list: exact words, or a host equal to or under a listed domain. */
    private static function matches(string $where, array $list): bool
    {
        $where = strtolower(preg_replace('/^www\./i', '', $where));
        foreach ($list as $item) {
            if (! str_contains($item, '.')) { if ($where === $item) return true; continue; }
            if (str_ends_with($item, '.')) { if (str_starts_with($where, $item) || str_contains($where, '.'.$item)) return true; continue; }
            if ($where === $item || str_ends_with($where, '.'.$item)) return true;
        }

        return false;
    }
}
