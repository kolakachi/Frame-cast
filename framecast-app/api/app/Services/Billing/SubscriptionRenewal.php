<?php

namespace App\Services\Billing;

use App\Models\CreditLedgerEntry;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\Onboarding\WelcomeMail;
use App\Services\RewardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** One payment-backed allocation path shared by webhook delivery and reconciliation. */
class SubscriptionRenewal
{
    private function get(string $path, array $query = []): array
    {
        $key = (string) config('billing.kelviq.server_api_key');
        if ($key === '') {
            throw new \RuntimeException('Kelviq reconciliation key is missing.');
        }
        $response = Http::withToken($key)->acceptJson()->timeout(15)
            ->get(rtrim(config('billing.kelviq.api_base'), '/').$path, $query)->throw();
        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('Invalid Kelviq response.');
        }

        return $data;
    }

    private function date(mixed $value): ?Carbon
    {
        return $value ? rescue(fn () => Carbon::parse($value), null, false) : null;
    }

    public function reconcile(Workspace $workspace, string $subscriptionId): void
    {
        if (in_array($workspace->plan_source, ['lifetime', 'appsumo'], true) && $workspace->kelviq_subscription_id === $subscriptionId) {
            return;
        }
        if ($subscriptionId === '') {
            throw new \RuntimeException('Missing subscription identity.');
        }
        $sub = $this->get('/subscriptions/'.rawurlencode($subscriptionId).'/');
        if (($sub['id'] ?? '') !== $subscriptionId) {
            throw new \RuntimeException('Subscription identity mismatch.');
        }
        $tier = config('billing.kelviq.plan_tiers', [])[$sub['plan']['identifier'] ?? ''] ?? null;
        if (! $tier) {
            throw new \RuntimeException('Unknown subscription plan.');
        }
        $status = strtolower($sub['status'] ?? 'pending');
        $start = $this->date($sub['billingPeriodStartTime'] ?? $sub['billing_period_start_time'] ?? null);
        $end = $this->date($sub['billingPeriodEndTime'] ?? $sub['billing_period_end_time'] ?? null);
        $version = $this->date($sub['modifiedOn'] ?? $sub['modified_on'] ?? null);
        if (! $version) {
            throw new \RuntimeException('Missing subscription state version.');
        }
        $cancelAt = $this->date($sub['endDate'] ?? $sub['end_date'] ?? null);
        if (! $cancelAt && in_array($status, ['cancelled', 'canceled'], true)) {
            $cancelAt = $version;
        }
        $previousId = $workspace->kelviq_subscription_id;
        if ($previousId && $previousId !== $subscriptionId) {
            $current = $this->get('/subscriptions/'.rawurlencode($previousId).'/');
            $candidateCreated = $this->date($sub['createdOn'] ?? $sub['created_on'] ?? null);
            $currentCreated = $this->date($current['createdOn'] ?? $current['created_on'] ?? null);
            // A replacement is adopted only after payment and only if newer.
            if (! $candidateCreated || ! $currentCreated || $candidateCreated->lte($currentCreated)) {
                return;
            }
        }

        $invoice = null;
        if (in_array($status, ['active', 'past_due', 'cancelled', 'canceled'], true)) {
            if (! $start || ! $end || $end->lte($start)) {
                throw new \RuntimeException('Missing authoritative subscription period.');
            }
            // Never follow provider-supplied URLs with an Authorization header.
            for ($page = 1; $page <= 100; $page++) {
                $list = $this->get('/invoices/', ['subscription_id' => $subscriptionId, 'page_size' => 100, 'page' => $page]);
                if (! isset($list['results']) || ! is_array($list['results'])) {
                    throw new \RuntimeException('Invalid invoice list.');
                }
                foreach ($list['results'] as $row) {
                    $created = $this->date($row['createdOn'] ?? $row['created_on'] ?? null);
                    // An old paid invoice is not proof that the current cycle is paid.
                    if (strtoupper($row['status'] ?? '') !== 'PAID' || empty($row['id'])
                        || ($row['subscription']['id'] ?? $row['subscription_id'] ?? '') !== $subscriptionId
                        || ($row['plan']['identifier'] ?? '') !== ($sub['plan']['identifier'] ?? '')
                        || ! $created || $created->lt($start->copy()->subMinutes(5)) || $created->gte($end)) {
                        continue;
                    }
                    $invoice = $row;
                    break 2;
                }
                if (empty($list['next'])) {
                    break;
                }
                if ($page === 100) {
                    throw new \RuntimeException('Invoice pagination limit exceeded.');
                }
            }
        }

        DB::transaction(function () use ($workspace, $subscriptionId, $previousId, $tier, $status, $start, $end, $version, $cancelAt, $invoice) {
            $ws = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($ws->kelviq_subscription_id !== $previousId) {
                throw new \RuntimeException('Subscription changed during reconciliation; retry.');
            }
            if ($previousId === $subscriptionId && $ws->billing_state_version && $ws->billing_state_version->gt($version)) {
                return;
            }
            // Pending replacements must never replace a paid subscription or a one-time plan.
            if ((! $previousId || $previousId !== $subscriptionId) && ! $invoice) {
                // Retain the identity of a first subscription for reconciliation,
                // without granting a tier or credits before its invoice is paid.
                if (! $previousId) {
                    $ws->forceFill(['kelviq_subscription_id' => $subscriptionId, 'billing_state_version' => $version])->save();
                }

                return;
            }
            $updates = ['plan_status' => $status, 'subscription_ends_at' => $cancelAt, 'billing_state_version' => $version];
            if ($invoice) {
                $paidUntil = $ws->billing_renews_at;
                $previousTier = (string) $ws->getRawOriginal('plan_tier');
                // Old paid periods may be recorded, but cannot overwrite a newer allocation.
                if (! $paidUntil || $end->gte($paidUntil)) {
                    $updates += ['kelviq_subscription_id' => $subscriptionId, 'plan_tier' => $tier,
                        'plan_source' => 'kelviq', 'billing_renews_at' => $end, 'plan_renews_at' => $end];
                    $receipt = DB::table('billing_credit_allocations')->where('invoice_id', $invoice['id'])->first();
                    if ($receipt && (int) $receipt->workspace_id !== (int) $ws->id) {
                        throw new \RuntimeException('Invoice belongs to another workspace.');
                    }
                    if (! $receipt) {
                        $allocation = CreditService::PLAN_CREDITS[$tier] ?? 0;
                        $before = (int) $ws->credits_monthly;
                        $samePeriod = $paidUntil && $end->lte($paidUntil);
                        $alreadyAllocated = $samePeriod ? max(
                            (int) DB::table('billing_credit_allocations')->where('workspace_id', $ws->id)->where('period_end', $end)->max('allocation'),
                            CreditService::PLAN_CREDITS[$previousTier] ?? 0,
                        ) : 0;
                        $after = $samePeriod ? $before + max(0, $allocation - $alreadyAllocated)
                            : CreditService::refilledMonthlyCredits($tier, $before);
                        DB::table('billing_credit_allocations')->insert([
                            'workspace_id' => $ws->id, 'invoice_id' => $invoice['id'], 'subscription_id' => $subscriptionId,
                            'period_end' => $end, 'tier' => $tier, 'allocation' => max($allocation, $alreadyAllocated),
                            'credit_delta' => $after - $before, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $updates['credits_monthly'] = $after;
                        CreditLedgerEntry::create(['workspace_id' => $ws->id, 'operation' => 'grant:monthly_kelviq',
                            'credits' => $before - $after, 'balance_after' => $after + (int) $ws->credits_topup,
                            'metadata' => ['invoice_id' => $invoice['id'], 'subscription_id' => $subscriptionId,
                                'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String(),
                                'monthly_before' => $before, 'monthly_after' => $after],
                        ]);
                    }
                    $updates += ['pending_checkout_plan' => null, 'pending_checkout_at' => null, 'pending_checkout_reminded_at' => null];
                }
            }
            $ws->forceFill($updates)->save();
            if ($invoice && $ws->plan_tier !== 'free') {
                WelcomeMail::sendOnce($ws);
                if (($previousTier ?? null) === 'free') {
                    DB::afterCommit(fn () => rescue(fn () => app(RewardService::class)->referralConversion($ws->fresh())));
                }
            }
        });
    }
}
