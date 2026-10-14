<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Services\KelviqService;
use Illuminate\Support\Facades\DB;

/**
 * Money in (owner, 2026-10-11, cost tracking step 2). Each Kelviq sale and renewal and each AppSumo licence becomes one
 * revenue_events row: what the buyer paid, discounts and tax, the platform's cut, refunds, and what reached us in US
 * dollars. Written idempotently (provider + external id), so a webhook replay, the daily sync and the backfill all
 * land on the same row.
 */
class RevenueService
{
    public function __construct(private KelviqService $kelviq) {}

    /** A Kelviq webhook event: a completed checkout or a paid renewal invoice. Anything else is ignored. */
    public function fromKelviqEvent(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $o = $event['data']['object'] ?? null;
        if (! is_array($o)) return;
        if ($type === 'checkout.completed') $this->kelviqSale($o, $this->kindFor($o), (string) ($o['order_id'] ?? $o['id'] ?? ''), $event);
        // A renewal: Kelviq sends an invoice for every purchase (one-time packs too), a minute after its checkout, so
        // only an invoice whose subscription already had an earlier one is a renewal; the first is the checkout itself
        // (prod backfill 2026-10-11: five "renewals" were each sale counted twice, with tax).
        $sub = (string) ($o['subscription_id'] ?? $o['subscription']['id'] ?? '');
        if ($type === 'invoice.paid' && $sub !== '' && $this->earlierInvoice($sub, (string) ($o['id'] ?? ''), $event)) {
            $this->kelviqSale($o, 'renewal', 'invoice:'.(string) ($o['id'] ?? ''), $event);
        }
    }

    private function kindFor(array $o): string
    {
        $planId = (string) ($o['plan']['identifier'] ?? '');
        if ($planId !== '' && $planId === (string) config('billing.kelviq.ugc_pass_plan')) return 'test_pass';
        if (isset(((array) config('billing.kelviq.lifetime_plans', []))[$planId])) return 'pack';
        if (array_key_exists($planId, (array) config('billing.kelviq.topup_plans', []))) return 'topup';
        return 'subscription';
    }

    private function kelviqSale(array $o, string $kind, string $externalId, array $event): void
    {
        if ($externalId === '' || $externalId === 'invoice:') return;
        $workspace = $this->kelviq->workspaceFor($o);
        $orderId = (string) ($o['order_id'] ?? $o['id'] ?? '');
        $breakdown = $kind === 'renewal' ? null : $this->kelviq->fetchOrderBreakdown($orderId);
        $currency = strtoupper((string) ($o['currency'] ?? 'USD')) ?: 'USD';
        $gross = (float) ($o['amount'] ?? $o['amount_total'] ?? $o['amount_paid'] ?? 0);
        if ($gross <= 0 && isset($o['amount_total_units'])) $gross = ((int) $o['amount_total_units']) / 100;
        $discount = $breakdown['discount'] ?? 0.0;
        $tax = $breakdown['tax'] ?? 0.0;
        $net = $breakdown ? max(0, $breakdown['subtotal'] - $breakdown['discount']) : $gross;
        if ($breakdown) $gross = $breakdown['total'];
        $netUsd = $currency === 'USD' ? $net : null;
        $plan = ! empty($o['metadata']['checkout_attempt_id'])
            ? (DB::table('billing_checkout_attempts')->where('id', $o['metadata']['checkout_attempt_id'])->value('plan') ?: null) : null;
        $this->upsert('kelviq', $externalId, [
            'workspace_id' => $workspace?->getKey(),
            'kind' => $kind,
            'plan' => $plan ?? ($o['plan']['identifier'] ?? null),
            'channel' => $this->channel($workspace, $orderId),
            'currency' => $currency,
            'gross' => round($gross, 2), 'discount' => round($discount, 2), 'tax' => round($tax, 2), 'net' => round($net, 2),
            'net_usd' => $netUsd !== null ? round($netUsd, 2) : null,
            'fee_usd' => 0, // set by kelviqFees(): the rate depends on how much went through Kelviq before this sale
            'refunded_usd' => $currency === 'USD' ? round((float) ($breakdown['refunded'] ?? 0), 2) : 0,
            'occurred_at' => self::when($event),
            'metadata' => ['order_id' => $orderId, 'breakdown' => $breakdown !== null, 'tax_known' => $breakdown !== null],
        ]);
        $this->kelviqFees();
    }

    /**
     * Kelviq's fee on every sale, in the order they happened: the intro rate until the volume processed passes the intro
     * cap (the sale that crosses it is split), the standard rate after, plus the fixed amount per payment, on the amount
     * charged. Recomputed whole, so a late or backfilled sale never leaves a later one at the wrong rate.
     */
    public function kelviqFees(): void
    {
        $intro = (float) config('pricing.revenue.kelviq_fee_intro_rate', 0.029);
        $rate = (float) config('pricing.revenue.kelviq_fee_rate', 0.035);
        $cap = (float) config('pricing.revenue.kelviq_fee_intro_volume', 5000);
        $fixed = (float) config('pricing.revenue.kelviq_fee_fixed', 0.40);
        $volume = 0.0;
        foreach (DB::table('revenue_events')->where('provider', 'kelviq')->orderBy('occurred_at')->orderBy('id')->get(['id', 'gross', 'currency']) as $r) {
            $gross = (float) $r->gross;
            if ($r->currency !== 'USD' || $gross <= 0) continue;
            $atIntro = max(0, min($gross, $cap - $volume));
            $fee = round($atIntro * $intro + ($gross - $atIntro) * $rate + $fixed, 2);
            $volume += $gross;
            DB::table('revenue_events')->where('id', $r->id)->update(['fee_usd' => $fee]);
        }
    }

    /** An AppSumo licence: the tier's list price, AppSumo's cut, and a full refund once it is deactivated. */
    public function fromAppSumoLicense(object $license): void
    {
        $tier = (int) ($license->appsumo_tier ?? 0);
        $prices = ($license->credit_schedule ?? null) === 'v2' ? 'pricing.revenue.appsumo_price_usd_v2' : 'pricing.revenue.appsumo_price_usd';
        $list = (float) (((array) config($prices, []))[$tier] ?? 0);
        // Bought during an AppSumo campaign: the list price less its discount.
        $bought = strtotime((string) ($license->created_at ?? 'now')) ?: time();
        $discount = 0.0;
        foreach ((array) config('pricing.revenue.appsumo_campaigns', []) as $c) {
            if ($bought >= strtotime($c['from']) && $bought <= strtotime($c['to'])) $discount = (float) $c['discount'];
        }
        $price = round($list * (1 - $discount), 2);
        $refunded = ($license->status ?? '') === 'deactivated';
        $this->upsert('appsumo', 'license:'.$license->id, [
            'workspace_id' => $license->workspace_id,
            'kind' => 'appsumo',
            'plan' => $license->tier ?? ('tier '.$tier),
            'channel' => 'appsumo',
            'currency' => 'USD',
            'gross' => $price, 'discount' => round($list - $price, 2), 'tax' => 0, 'net' => $price, 'net_usd' => $price,
            'fee_usd' => round($price * (float) config('pricing.revenue.appsumo_cut', 0.75), 2),
            'refunded_usd' => $refunded ? round($price * (1 - (float) config('pricing.revenue.appsumo_cut', 0.75)), 2) : 0,
            'occurred_at' => $license->created_at ?? now(),
            'metadata' => ['appsumo_tier' => $tier, 'status' => $license->status ?? null, 'list_usd' => $list, 'campaign_discount' => $discount, 'price_known' => $price > 0],
        ]);
    }

    /** Refunds of recent Kelviq orders, and every AppSumo licence's tier and status (daily). */
    public function sync(int $days = 90): array
    {
        $n = ['kelviq' => 0, 'appsumo' => 0];
        DB::table('revenue_events')->where('provider', 'kelviq')->where('kind', '!=', 'renewal')->where('occurred_at', '>=', now()->subDays($days))
            ->orderBy('id')->each(function ($r) use (&$n) {
                $orderId = (string) (json_decode((string) $r->metadata, true)['order_id'] ?? '');
                $b = $orderId !== '' ? $this->kelviq->fetchOrderBreakdown($orderId) : null;
                if ($b && $r->currency === 'USD' && round($b['refunded'], 2) !== round((float) $r->refunded_usd, 2)) {
                    DB::table('revenue_events')->where('id', $r->id)->update(['refunded_usd' => round($b['refunded'], 2), 'updated_at' => now()]);
                    $n['kelviq']++;
                }
            });
        DB::table('appsumo_licenses')->orderBy('id')->each(function ($l) use (&$n) { $this->fromAppSumoLicense($l); $n['appsumo']++; });
        $this->kelviqFees();
        return $n;
    }

    /** Past sales, from the stored Kelviq webhooks and the AppSumo licences. */
    public function backfill(): array
    {
        $n = 0;
        DB::table('billing_webhook_logs')->where('provider', 'kelviq')->whereIn('event', ['checkout.completed', 'invoice.paid'])
            ->where('signature_valid', true)->orderBy('id')->each(function ($log) use (&$n) {
                $event = json_decode((string) $log->payload, true);
                if (is_array($event)) { $event['_logged_at'] = $log->created_at; rescue(fn () => $this->fromKelviqEvent($event), null, false); $n++; }
            });
        $s = $this->sync(36500);
        return ['kelviq_events' => $n, 'appsumo_licenses' => $s['appsumo']];
    }

    /** Whether this subscription was invoiced before this invoice (from the stored webhooks). */
    private function earlierInvoice(string $subscriptionId, string $invoiceId, array $event): bool
    {
        $before = self::when($event);
        return DB::table('billing_webhook_logs')->where('provider', 'kelviq')->where('event', 'invoice.paid')->where('signature_valid', true)
            ->where('payload', 'like', '%'.$subscriptionId.'%')->where('created_at', '<', $before)
            ->when($invoiceId !== '', fn ($q) => $q->where('payload', 'not like', '%'.$invoiceId.'%'))->exists();
    }

    /** When the sale happened: the stored webhook's time on a backfill, the event's own time, or now. */
    private static function when(array $event): string
    {
        $t = $event['_logged_at'] ?? $event['created_at'] ?? null;
        if (is_numeric($t)) return date('Y-m-d H:i:s', (int) $t > 1e11 ? intdiv((int) $t, 1000) : (int) $t);
        if (is_string($t) && ($u = strtotime($t))) return date('Y-m-d H:i:s', $u);
        return now()->toDateTimeString();
    }

    private function channel(?Workspace $workspace, string $orderId): string
    {
        if ($orderId !== '' && DB::table('affiliate_conversions')->where('order_id', $orderId)->exists()) return 'affiliate';
        if ($workspace && DB::table('appsumo_licenses')->where('workspace_id', $workspace->getKey())->exists()) return 'appsumo';
        // Otherwise where the account came from (Acquisition): google_ads, search, ai, social…
        return (string) (($workspace ? DB::table('workspaces')->where('id', $workspace->getKey())->value('acquisition_channel') : null) ?: 'unknown');
    }

    private function upsert(string $provider, string $externalId, array $row): void
    {
        $row['metadata'] = json_encode($row['metadata'] ?? []);
        $row['updated_at'] = now();
        $exists = DB::table('revenue_events')->where('provider', $provider)->where('external_id', $externalId)->exists();
        if ($exists) {
            unset($row['occurred_at']); // a replay or a sync never moves when the sale happened
            DB::table('revenue_events')->where('provider', $provider)->where('external_id', $externalId)->update($row);
        } else {
            DB::table('revenue_events')->insert($row + ['provider' => $provider, 'external_id' => $externalId, 'created_at' => now()]);
        }
    }
}
