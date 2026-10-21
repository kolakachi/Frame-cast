<?php

namespace App\Services\Launch;

/**
 * What each Launchpad product and bump gives and costs (owner-approved offers, 2026-10-12; revised 2026-10-21;
 * docs/product/todo.md (LAUNCHPAD; history in docs/product/archive/2026-10-16/launchpad-gating-todo.md)).
 *
 * A main product sets a tier (its limits are that tier's row in CreditService::PLAN_LIMITS) and grants credits once.
 * A bump belongs to one main product ("of") and either grants credits or adds to a limit ("add"). Prices are in USD
 * and only used to recognise bumps in a Launchpad IPN (LaunchpadIpn::products).
 */
final class LaunchCatalogue
{
    public const PRODUCTS = [
        'fe'             => ['name' => 'WyvStudio',                         'price' => 7,   'tier' => 'launch_fe',     'credits' => 200],
        'fe_credits'     => ['name' => '+600 credits',                      'price' => 17,  'of' => 'fe',     'credits' => 600],
        'bundle'         => ['name' => 'WyvStudio Bundle',                  'price' => 247, 'tier' => 'launch_bundle', 'credits' => 8000],
        'bundle_dfy'     => ['name' => '+20 DFY templates',                 'price' => 21,  'of' => 'bundle', 'add' => ['sample_vault' => 20]],
        'pro'            => ['name' => 'WyvStudio Pro',                     'price' => 67,  'tier' => 'launch_pro',    'credits' => 1500],
        'pro_seats'      => ['name' => '+3 characters & +3 cloned voices',  'price' => 19,  'of' => 'pro',    'add' => ['max_characters' => 3, 'voice_cloning_limit' => 3]],
        'agency'         => ['name' => 'WyvStudio Agency',                  'price' => 347, 'tier' => 'launch_agency', 'credits' => 3000],
        'agency_credits' => ['name' => '+2,000 credits',                    'price' => 69,  'of' => 'agency', 'credits' => 2000],
    ];

    /** Which launch tier a workspace sits on when it owns several: Agency has everything (2026-10-21). */
    public const TIER_RANK = ['launch_fe' => 1, 'launch_pro' => 2, 'launch_bundle' => 3, 'launch_agency' => 4];

    /** The bump sold with a main product, if any. */
    public static function bumpOf(string $main): ?string
    {
        foreach (self::PRODUCTS as $key => $p) {
            if (($p['of'] ?? null) === $main) return $key;
        }

        return null;
    }

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
