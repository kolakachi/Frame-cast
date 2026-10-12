<?php

namespace App\Services\Launch;

/**
 * What each Launchpad product and bump gives (owner-approved offers, 2026-10-12; docs/product/launchpad-gating-todo.md).
 *
 * A main product sets a tier (its limits are that tier's row in CreditService::PLAN_LIMITS) and grants credits once.
 * A bump either grants credits, raises a limit to at least a value ("limits"), or adds to one ("add"). Content-only
 * bumps (100 Hooks & CTAs) are delivered by the marketer and change nothing here.
 */
final class LaunchCatalogue
{
    public const PRODUCTS = [
        'fe'             => ['name' => 'WyvStudio',                      'tier' => 'launch_fe',     'credits' => 200],
        'fe_credits'     => ['name' => 'Credit Booster',                 'credits' => 600],
        'fe_hooks'       => ['name' => '100 Hooks & CTAs',               'credits' => 0],
        'bundle'         => ['name' => 'WyvStudio Bundle',               'tier' => 'launch_bundle', 'credits' => 8000],
        'bundle_dfy'     => ['name' => '+20 DFY templates',              'add' => ['sample_vault' => 20]],
        'bundle_length'  => ['name' => 'Videos up to 3 minutes',         'limits' => ['max_duration_seconds' => 180]],
        'pro'            => ['name' => 'WyvStudio Pro',                  'tier' => 'launch_pro',    'credits' => 1500],
        'pro_credits'    => ['name' => '+1,000 credits',                 'credits' => 1000],
        'pro_seats'      => ['name' => '+2 characters & +2 cloned voices', 'add' => ['max_characters' => 2, 'voice_cloning_limit' => 2]],
        'agency'         => ['name' => 'WyvStudio Agency',               'tier' => 'launch_agency', 'credits' => 3000],
        'agency_clients' => ['name' => '+10 client accounts',            'add' => ['max_client_workspaces' => 10]],
        'agency_credits' => ['name' => '+2,000 credits',                 'credits' => 2000],
    ];

    /** Which launch tier a workspace sits on when it owns several: the Bundle is the fullest. */
    public const TIER_RANK = ['launch_fe' => 1, 'launch_pro' => 2, 'launch_agency' => 3, 'launch_bundle' => 4];

    public static function product(string $key): ?array
    {
        return self::PRODUCTS[$key] ?? null;
    }

    public static function isLaunchTier(?string $tier): bool
    {
        return isset(self::TIER_RANK[(string) $tier]);
    }

    /**
     * The more generous of two limit sets, key by key: null (no limit) beats any number, the larger number wins,
     * true beats false. For a flag, null means "today's rule applies" (CreditService::GATE_DEFAULTS): true beats it,
     * false never does, so a launch product cannot switch off something another plan already gives.
     * A key only one side has is taken as it is.
     */
    public static function generous(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (! array_key_exists($k, $a)) {
                $a[$k] = $v;
            } elseif (is_bool($v) || is_bool($a[$k])) {
                $a[$k] = $a[$k] === true || $v === true ? true : (($a[$k] === null || $v === null) ? null : false);
            } elseif ($a[$k] === null || $v === null) {
                $a[$k] = null;
            } elseif (is_numeric($v) && is_numeric($a[$k])) {
                $a[$k] = max($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }

        return $a;
    }

    /**
     * What a set of owned products adds up to: the main products' tier rows and the "limits" bumps merged generously,
     * then the "add" bumps added on top (no limit stays no limit).
     *
     * @param  list<string>  $owned
     * @return array{tier: ?string, limits: array<string, mixed>}
     */
    public static function combine(array $owned): array
    {
        $tier = null;
        $limits = [];
        $adds = [];
        foreach ($owned as $key) {
            $p = self::product($key);
            if ($p === null) continue;
            if (isset($p['tier'])) {
                $limits = self::generous($limits, \App\Services\CreditService::PLAN_LIMITS[$p['tier']]);
                $limits = self::generous($limits, \App\Services\WorkspaceUsageService::LAUNCH_USAGE[$p['tier']]);
                if ($tier === null || self::TIER_RANK[$p['tier']] > self::TIER_RANK[$tier]) $tier = $p['tier'];
            }
            if (isset($p['limits'])) $limits = self::generous($limits, $p['limits']);
            foreach ($p['add'] ?? [] as $k => $n) $adds[$k] = ($adds[$k] ?? 0) + $n;
        }
        foreach ($adds as $k => $n) {
            if (! array_key_exists($k, $limits)) $limits[$k] = $n;
            elseif ($limits[$k] !== null) $limits[$k] += $n;
        }

        return ['tier' => $tier, 'limits' => $limits];
    }
}
