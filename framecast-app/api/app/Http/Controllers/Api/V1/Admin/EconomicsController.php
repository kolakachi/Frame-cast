<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer economics (owner, 2026-10-11, cost tracking step 3): per workspace, what reached us (revenue_events after the
 * platform's cut and refunds) against what it cost us (api_usage_events, real since FULL_SINCE; before that, the credit
 * ledger's estimates), with credits granted and used, and totals by channel. Super admin only (the admin route group).
 */
class EconomicsController extends Controller
{
    /** Every paid call records its real cost from here on (VendorUsageRecorder); earlier cost is partly estimated. */
    public const FULL_SINCE = '2026-10-11 16:00:00';

    public function index(Request $request): JsonResponse
    {
        $days = in_array($request->query('days'), ['30', '90'], true) ? (int) $request->query('days') : null;
        $since = $days ? now()->subDays($days) : null;
        $paidOnly = $request->query('paid_only', '1') !== '0';

        $rev = DB::table('revenue_events')->when($since, fn ($q) => $q->where('occurred_at', '>=', $since))->get()->groupBy('workspace_id');
        $cost = DB::table('api_usage_events')->whereNotNull('workspace_id')->when($since, fn ($q) => $q->where('occurred_at', '>=', $since))
            ->groupBy('workspace_id')->selectRaw('workspace_id, SUM(estimated_cost_usd) as usd')->pluck('usd', 'workspace_id');
        $earlier = DB::table('credit_ledger')->where('created_at', '<', self::FULL_SINCE)->whereNotNull('upstream_cost_usd')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('workspace_id')->selectRaw('workspace_id, SUM(upstream_cost_usd) as usd')->pluck('usd', 'workspace_id');
        $credits = DB::table('credit_ledger')->when($since, fn ($q) => $q->where('created_at', '>=', $since))->groupBy('workspace_id')
            ->selectRaw("workspace_id, SUM(CASE WHEN operation LIKE 'grant:%' THEN -credits ELSE 0 END) as granted, SUM(CASE WHEN operation LIKE 'grant:%' THEN 0 ELSE credits END) as used")
            ->get()->keyBy('workspace_id');

        // Finished videos downloaded (owner, 2026-10-15), from Create, UGC or the Classic editor, in the app or by the API.
        $hasDownloads = \Illuminate\Support\Facades\Schema::hasTable('video_downloads');
        $downloads = $hasDownloads ? DB::table('video_downloads')->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('workspace_id')->selectRaw('workspace_id, count(*) as n')->pluck('n', 'workspace_id') : collect();
        $ids = collect($rev->keys())->merge($cost->keys())->merge($earlier->keys())->filter()->unique()->values();
        // Sales with no account behind them (an AppSumo licence bought but never redeemed) still reached us: one row.
        if ($rev->has('')) $ids->push('');
        $real = $ids->filter(fn ($id) => $id !== '')->values(); // Postgres refuses '' for a bigint
        $workspaces = DB::table('workspaces')->whereIn('id', $real)->get()->keyBy('id');
        $owners = DB::table('users')->whereIn('workspace_id', $real)->orderBy('id')->get(['workspace_id', 'email'])->unique('workspace_id')->pluck('email', 'workspace_id');

        $rows = $ids->map(function ($id) use ($rev, $cost, $earlier, $credits, $workspaces, $owners, $downloads) {
            $sales = $rev->get($id, collect());
            $paid = (float) $sales->sum(fn ($r) => (float) ($r->net_usd ?? 0));
            $fees = (float) $sales->sum('fee_usd');
            $refunds = (float) $sales->sum('refunded_usd');
            $in = $paid - $fees - $refunds;
            $real = (float) ($cost[$id] ?? 0);
            $est = (float) ($earlier[$id] ?? 0);
            $w = $workspaces->get($id);
            $c = $credits->get($id);
            return [
                'workspace_id' => (int) $id,
                'name' => $id === '' ? 'Not linked to an account (unredeemed licences)' : ($w->name ?? ('Workspace '.$id)),
                'owner_email' => $owners[$id] ?? null,
                'plan' => $w->plan_tier ?? null,
                'client_of' => $w->parent_workspace_id ?? null,
                'channels' => $sales->pluck('channel')->filter()->unique()->values(),
                // Where the account came from, and the detail it was read from (Acquisition).
                'acquisition' => $w->acquisition_channel ?? null,
                'acquisition_detail' => self::detail($w),
                'sales' => $sales->count(),
                'first_sale_at' => $sales->min('occurred_at'),
                'paid_usd' => round($paid, 2),
                'platform_fees_usd' => round($fees, 2),
                'refunded_usd' => round($refunds, 2),
                'money_in_usd' => round($in, 2),
                'cost_recorded_usd' => round($real, 2),
                'cost_earlier_estimate_usd' => round($est, 2),
                'margin_usd' => round($in - $real - $est, 2),
                'credits_granted' => (int) ($c->granted ?? 0),
                'credits_used' => (int) ($c->used ?? 0),
                'downloads' => (int) ($downloads[$id] ?? 0),
                'unpriced_sales' => $sales->whereNull('net_usd')->count(),
            ];
        })->filter(fn ($r) => ! $paidOnly || $r['sales'] > 0)->sortByDesc('money_in_usd')->values();

        $byChannel = DB::table('revenue_events')->when($since, fn ($q) => $q->where('occurred_at', '>=', $since))->get()
            ->groupBy(fn ($r) => $r->channel ?: 'unknown')->map(fn ($g, $ch) => [
                'channel' => $ch, 'sales' => $g->count(),
                'money_in_usd' => round((float) $g->sum(fn ($r) => (float) ($r->net_usd ?? 0)) - (float) $g->sum('fee_usd') - (float) $g->sum('refunded_usd'), 2),
                'refunded_sales' => $g->filter(fn ($r) => (float) $r->refunded_usd > 0)->count(),
            ])->values();

        // Accounts by where they came from, as one cohort: those that signed up in the period (all, without one), how
        // many of them have paid, and what they have brought in since. Client workspaces an agency made are not sign-ups.
        $paying = DB::table('revenue_events')->whereNotNull('workspace_id')->get()->groupBy('workspace_id')
            ->map(fn ($g) => (float) $g->sum(fn ($r) => (float) ($r->net_usd ?? 0)) - (float) $g->sum('fee_usd') - (float) $g->sum('refunded_usd'));
        $accounts = DB::table('workspaces')->whereNull('parent_workspace_id')->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->get(['id', 'acquisition_channel']);
        $bySource = $accounts->groupBy(fn ($w) => $w->acquisition_channel ?: 'unknown')->map(function ($g, $ch) use ($paying) {
            $payers = $g->filter(fn ($w) => $paying->has($w->id));
            return ['channel' => $ch, 'signups' => $g->count(), 'paying' => $payers->count(),
                'conversion_pct' => round(100 * $payers->count() / max(1, $g->count()), 1),
                'money_in_usd' => round((float) $payers->sum(fn ($w) => $paying[$w->id]), 2)];
        })->sortByDesc(fn ($r) => [$r['money_in_usd'], $r['signups']])->values();

        $totals = ['money_in_usd' => round((float) $rows->sum('money_in_usd'), 2), 'cost_recorded_usd' => round((float) $rows->sum('cost_recorded_usd'), 2),
            'cost_earlier_estimate_usd' => round((float) $rows->sum('cost_earlier_estimate_usd'), 2), 'margin_usd' => round((float) $rows->sum('margin_usd'), 2),
            'refunded_usd' => round((float) $rows->sum('refunded_usd'), 2), 'paying_workspaces' => $rows->where('sales', '>', 0)->count()];
        // What paid calls cost us with no workspace to put them against (the company's own tools, scripts).
        $unattributed = round((float) DB::table('api_usage_events')->whereNull('workspace_id')->when($since, fn ($q) => $q->where('occurred_at', '>=', $since))->sum('estimated_cost_usd'), 2);

        return response()->json(['data' => [
            'rows' => $rows, 'by_channel' => $byChannel, 'by_source' => $bySource, 'totals' => $totals, 'unattributed_cost_usd' => $unattributed,
            'downloads' => $hasDownloads ? DB::table('video_downloads')->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->groupBy('source', 'via')->selectRaw('source, via, count(*) as downloads, count(distinct workspace_id) as workspaces')
                ->orderByDesc('downloads')->get()->map(fn ($d) => ['source' => $d->source, 'via' => $d->via, 'downloads' => (int) $d->downloads, 'workspaces' => (int) $d->workspaces])->all() : [],
            'full_cost_since' => self::FULL_SINCE, 'period_days' => $days,
        ], 'meta' => []]);
    }

    /** "Google ad · campaign spring", "chatgpt.com", "utm facebook / cpc": what the channel was read from. */
    private static function detail(?object $w): ?string
    {
        $t = $w && $w->acquisition_json ? (json_decode((string) $w->acquisition_json, true) ?: []) : [];
        if (! $t) return null;
        $parts = array_filter([
            ! empty($t['gclid']) || ! empty($t['gbraid']) || ! empty($t['wbraid']) ? 'Google ad click' : null,
            ! empty($t['utm_source']) ? 'utm '.$t['utm_source'].(! empty($t['utm_medium']) ? ' / '.$t['utm_medium'] : '') : null,
            ! empty($t['utm_campaign']) ? 'campaign '.$t['utm_campaign'] : null,
            $t['ref_host'] ?? null,
            ! empty($t['landing']) && $t['landing'] !== '/' ? 'landed on '.$t['landing'] : null,
        ]);

        return $parts ? mb_substr(implode(' · ', $parts), 0, 200) : 'no referrer';
    }
}
