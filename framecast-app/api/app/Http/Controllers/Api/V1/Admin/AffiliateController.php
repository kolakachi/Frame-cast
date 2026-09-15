<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePaymentDetail;
use App\Models\AffiliatePayout;
use App\Services\Affiliate\ExchangeRateService;
use App\Services\Affiliate\PayoutEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Managing affiliates and paying them.
 *
 * Commission is read from the conversion rows, never recalculated from the
 * affiliate's current rate — a renegotiated percentage must not quietly
 * restate what is owed on sales already made.
 *
 * Settlement goes through a payout run rather than a flag per sale, so that
 * "paid" always names a specific payment covering a specific set of sales.
 * See the payouts migration for why.
 */
class AffiliateController extends Controller
{
    public function index(): JsonResponse
    {
        $affiliates = Affiliate::query()->withCount('clicks')->orderBy('name')->get()->map(function (Affiliate $a) {
            $rows = AffiliateConversion::query()->where('affiliate_id', $a->getKey())->get();
            $outstanding = $rows->where('payout_status', 'unpaid');
            $settled = $rows->where('payout_status', 'paid');
            $last = AffiliatePayout::query()->where('affiliate_id', $a->getKey())
                ->where('status', 'paid')->latest('paid_at')->first();
            $eligibility = PayoutEligibility::summarise($a);

            return [
                'id'      => $a->getKey(),
                'code'    => $a->code,
                'name'    => $a->name,
                'email'   => $a->email,
                'commission_percent' => (float) $a->commission_percent,
                'status'  => $a->status,
                'link'    => rtrim((string) config('app.marketing_url', 'https://wyvstudio.com'), '/').'/?ref='.$a->code,
                'clicks'  => (int) $a->clicks_count,
                'sales'   => $rows->count(),
                'revenue' => round((float) $rows->sum('order_amount'), 2),

                // Outstanding and settled are kept apart at every level. A single
                // lifetime figure cannot be checked against anything once more
                // than one payment has been made.
                'owed'    => round((float) $outstanding->sum('commission_amount'), 2),
                'owed_sales' => $outstanding->count(),
                'paid'    => round((float) $settled->sum('commission_amount'), 2),
                'paid_sales' => $settled->count(),

                // What has come in since the last payment was sent — the number
                // that answers "did this affiliate earn anything new?".
                'payouts_count' => AffiliatePayout::query()->where('affiliate_id', $a->getKey())->where('status', 'paid')->count(),
                'last_payout' => $last ? [
                    'reference' => $last->reference,
                    'amount' => round((float) $last->total_amount, 2),
                    'payout_amount' => $last->payout_amount !== null ? (float) $last->payout_amount : null,
                    'payout_currency' => $last->payout_currency,
                    'paid_at' => $last->paid_at?->toDateString(),
                    'sales_count' => (int) $last->sales_count,
                ] : null,
                'oldest_unpaid' => $outstanding->min('created_at')?->toDateString(),

                // Owed is not the same as payable once a hold exists, and the
                // difference is what determines whether a run can go out.
                'available' => $eligibility['available'],
                'maturing' => $eligibility['maturing'],
                'next_matures_at' => $eligibility['next_matures_at'],
                'details_status' => $eligibility['details_status'],
                'payout_blocked_reason' => $eligibility['blocked_reason'],
                'next_payout_date' => PayoutEligibility::nextPayoutDate($a),

                // Shown so they can be sent on. This endpoint is already behind
                // the admin gate; the key is hidden from every other response.
                'access_key' => $a->access_key,
                'last_login_at' => $a->last_login_at?->toDateString(),
            ];
        });

        return response()->json(['data' => ['affiliates' => $affiliates], 'meta' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        // Lowercased before the uniqueness check, not after: lookups are
        // case-insensitive, so "JANE" and "jane" are one code, and validating
        // the raw string would let the second one through to a 500 on the index.
        if ($request->filled('code')) {
            $request->merge(['code' => Str::lower(trim((string) $request->input('code')))]);
        }

        $v = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'code'  => ['nullable', 'string', 'max:32', 'alpha_dash', 'unique:affiliates,code'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Generated unless one was asked for by name — see Affiliate::generateCode
        // for why the default carries nothing about the affiliate.
        $code = isset($v['code']) && $v['code'] !== '' ? $v['code'] : Affiliate::generateCode();

        $affiliate = Affiliate::query()->create([
            'code' => $code,
            'name' => $v['name'],
            'email' => $v['email'] ?? null,
            'commission_percent' => $v['commission_percent'],
            'notes' => $v['notes'] ?? null,
            'status' => 'active',
            // Issued up front so there is never an affiliate who cannot sign in.
            'access_key' => Affiliate::generateAccessKey(),
        ]);

        // Send it rather than making someone retype it. Rescued because a mail
        // outage must not lose an affiliate we have already created — the
        // details stay copyable from the Sign-in details tab either way.
        if ($affiliate->email) {
            rescue(fn () => \Illuminate\Support\Facades\Mail::to($affiliate->email)
                ->queue(new \App\Mail\Affiliate\AffiliateWelcomeMail($affiliate, (string) $affiliate->access_key)));
        }

        return response()->json(['data' => ['affiliate' => array_merge(
            $affiliate->toArray(),
            ['access_key' => $affiliate->access_key, 'welcome_sent' => (bool) $affiliate->email],
        )], 'meta' => []], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $affiliate = Affiliate::query()->findOrFail($id);
        $v = $request->validate([
            'name'  => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'status' => ['sometimes', 'in:active,paused'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $affiliate->fill($v)->save();

        // Deliberately does not touch existing conversions: a new rate applies
        // to sales from here, not to ones already earned.
        return response()->json(['data' => ['affiliate' => $affiliate->fresh()], 'meta' => []]);
    }

    /**
     * Replace the portal key. Signs the affiliate out everywhere, since the
     * reason to do this is usually that someone else has the old one.
     */
    public function regenerateKey(int $id): JsonResponse
    {
        $affiliate = Affiliate::query()->findOrFail($id);
        $affiliate->forceFill(['access_key' => Affiliate::generateAccessKey()])->save();
        \App\Models\AffiliateSession::query()->where('affiliate_id', $affiliate->getKey())->delete();

        // A new key nobody told them about just locks them out.
        if ($affiliate->email) {
            rescue(fn () => \Illuminate\Support\Facades\Mail::to($affiliate->email)
                ->queue(new \App\Mail\Affiliate\AffiliateWelcomeMail($affiliate, (string) $affiliate->access_key)));
        }

        return response()->json(['data' => [
            'access_key' => $affiliate->access_key,
            'emailed' => (bool) $affiliate->email,
        ], 'meta' => []]);
    }

    public function conversions(Request $request, int $id): JsonResponse
    {
        $query = AffiliateConversion::query()->where('affiliate_id', $id);

        // Defaults to everything; the UI narrows to outstanding when the
        // question is "what do I owe right now".
        if ($request->string('filter')->toString() === 'unpaid') {
            $query->where('payout_status', 'unpaid');
        }

        $rows = $query->orderByDesc('created_at')->limit(500)->get();
        $references = AffiliatePayout::query()
            ->whereIn('id', $rows->pluck('payout_id')->filter()->unique())
            ->pluck('reference', 'id');

        return response()->json(['data' => ['conversions' => $rows->map(fn (AffiliateConversion $c) => [
            'id' => $c->getKey(),
            'date' => $c->created_at?->toDateString(),
            'customer_email' => $c->customer_email,
            'order_id' => $c->order_id,
            'plan' => $c->plan,
            'order_amount' => (float) $c->order_amount,
            'basis_amount' => (float) ($c->basis_amount ?: $c->order_amount),
            'commission_percent' => (float) $c->commission_percent,
            'commission_amount' => (float) $c->commission_amount,
            'attribution_source' => $c->attribution_source,
            'payout_status' => $c->payout_status,
            // Which payment settled it, so a row is traceable to a transfer.
            'payout_reference' => $c->payout_id ? ($references[$c->payout_id] ?? null) : null,
        ])], 'meta' => []]);
    }

    /**
     * The account we would send money to, with the number in the clear.
     *
     * Admin-only and deliberately its own endpoint: the affiliate listing must
     * never carry a bank number, so it is fetched when someone is actually
     * about to make a transfer rather than shipped with every page load.
     */
    public function paymentDetails(int $id): JsonResponse
    {
        $d = AffiliatePaymentDetail::query()->where('affiliate_id', $id)->first();
        if (! $d) {
            return response()->json(['data' => ['payment_details' => null], 'meta' => []]);
        }

        return response()->json(['data' => ['payment_details' => [
            'id' => $d->getKey(),
            'account_name' => $d->account_name,
            'bank_name' => $d->bank_name,
            'account_number' => $d->account_number,
            'bank_code' => $d->bank_code,
            'country' => $d->country,
            'payout_currency' => $d->payout_currency,
            'status' => $d->status,
            'verified_at' => $d->verified_at?->toDateString(),
            'rejected_reason' => $d->rejected_reason,
            'submitted_at' => $d->submitted_at?->toDateString(),
        ]], 'meta' => []]);
    }

    /** Approve or reject the account, which is what unblocks a payout run. */
    public function verifyPaymentDetails(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'status' => ['required', 'in:verified,rejected,unverified'],
            'reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $d = AffiliatePaymentDetail::query()->where('affiliate_id', $id)->firstOrFail();
        $d->forceFill([
            'status' => $v['status'],
            'verified_at' => $v['status'] === 'verified' ? now() : null,
            'verified_by_user_id' => $v['status'] === 'verified' ? $request->user()?->getKey() : null,
            'rejected_reason' => $v['status'] === 'rejected' ? $v['reason'] : null,
        ])->save();

        return response()->json(['data' => ['status' => $d->status], 'meta' => []]);
    }

    /**
     * Move a payment along its lifecycle.
     *
     * "Paid" is not the only true state: a transfer can be sitting with the
     * bank, or can bounce. A run stuck on "paid" when the money never arrived
     * is how an affiliate gets told they were paid and finds nothing.
     */
    public function updatePayoutStatus(Request $request, int $id, int $payoutId): JsonResponse
    {
        $v = $request->validate([
            'status' => ['required', 'in:pending,processing,paid,failed'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'failure_reason' => ['required_if:status,failed', 'nullable', 'string', 'max:500'],
            'fx_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $payout = AffiliatePayout::query()->where('affiliate_id', $id)->findOrFail($payoutId);
        if ($payout->status === 'void') {
            return response()->json(['error' => ['message' => 'That payout is void.']], 422);
        }

        $update = [
            'status' => $v['status'],
            'failure_reason' => $v['status'] === 'failed' ? $v['failure_reason'] : null,
            'paid_at' => $v['status'] === 'paid' ? ($payout->paid_at ?? now()) : $payout->paid_at,
        ];
        if (array_key_exists('payment_reference', $v)) {
            $update['payment_reference'] = $v['payment_reference'];
        }
        // A corrected rate restates the naira figure with it, so the two can
        // never disagree on a statement.
        if (! empty($v['fx_rate'])) {
            $update['fx_rate'] = (float) $v['fx_rate'];
            $update['payout_amount'] = round((float) $payout->total_amount * (float) $v['fx_rate'], 2);
            $update['fx_source'] = 'entered at payout';
            $update['fx_captured_at'] = now();
        }

        $payout->forceFill($update)->save();

        return response()->json(['data' => ['status' => $payout->status], 'meta' => []]);
    }

    /** Every payment sent to this affiliate, newest first. */
    public function payouts(int $id): JsonResponse
    {
        $rows = AffiliatePayout::query()->where('affiliate_id', $id)
            ->orderByDesc('created_at')->limit(200)->get()
            ->map(fn (AffiliatePayout $p) => [
                'id' => $p->getKey(),
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toDateString(),
                'period_start' => $p->period_start?->toDateString(),
                'period_end' => $p->period_end?->toDateString(),
                'sales_count' => (int) $p->sales_count,
                'total_amount' => (float) $p->total_amount,
                'currency' => $p->currency,
                'payout_amount' => $p->payout_amount !== null ? (float) $p->payout_amount : null,
                'payout_currency' => $p->payout_currency,
                'fx_rate' => $p->fx_rate !== null ? (float) $p->fx_rate : null,
                'fx_source' => $p->fx_source,
                'payment_reference' => $p->payment_reference,
                'method' => $p->method,
                'note' => $p->note,
                'status' => $p->status,
                'void_reason' => $p->void_reason,
                'failure_reason' => $p->failure_reason,
            ]);

        return response()->json(['data' => ['payouts' => $rows], 'meta' => []]);
    }

    /**
     * Settle what is outstanding as one payment.
     *
     * Membership is fixed here, inside the lock, and the statement is rendered
     * from it afterwards. A sale landing a second later is simply outstanding
     * again, which is the correct answer rather than an accident.
     */
    public function createPayout(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'conversion_ids' => ['sometimes', 'array'],
            'conversion_ids.*' => ['integer'],
            // Lets a run be cut at a period end rather than at whenever the
            // button happened to be pressed.
            'up_to' => ['sometimes', 'nullable', 'date'],
            'method' => ['sometimes', 'nullable', 'string', 'max:32'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // The rate actually achieved on the transfer. Left out, the live
            // rate is recorded instead — but the one you got is the one that
            // belongs on the statement.
            'fx_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Deliberate override of the details/minimum gate.
            'force' => ['sometimes', 'boolean'],
        ]);

        $affiliate = Affiliate::query()->findOrFail($id);

        try {
            $payout = DB::transaction(function () use ($affiliate, $v, $request) {
                // Only what has cleared the refund window. Paying inside it is
                // how a payout becomes a clawback conversation.
                $query = PayoutEligibility::matured((int) $affiliate->getKey())->lockForUpdate();

                if (! empty($v['conversion_ids'])) {
                    $query->whereIn('id', $v['conversion_ids']);
                }
                if (! empty($v['up_to'])) {
                    $query->where('created_at', '<=', \Carbon\Carbon::parse($v['up_to'])->endOfDay());
                }

                $rows = $query->get();
                if ($rows->isEmpty()) {
                    $held = PayoutEligibility::maturing((int) $affiliate->getKey())->count();
                    throw new \RuntimeException($held > 0
                        ? "Nothing has matured yet — {$held} commission(s) are still inside the "
                            .config('affiliates.hold_days', 21).'-day refund window.'
                        : 'There is nothing outstanding to pay.');
                }

                // Money needs somewhere to go, and somewhere we have checked.
                $status = PayoutEligibility::summarise($affiliate);
                if (! $status['details_ok'] && ! ($v['force'] ?? false)) {
                    throw new \RuntimeException($status['blocked_reason'] ?? 'Payment details are not ready.');
                }
                if (! $status['meets_minimum'] && ! ($v['force'] ?? false)) {
                    throw new \RuntimeException('Below the minimum payout of '
                        .number_format((float) config('affiliates.minimum_payout', 0), 2).'.');
                }

                // Summing across currencies would produce a number that is not
                // an amount in any of them.
                $currencies = $rows->pluck('currency')->map(fn ($c) => strtoupper((string) $c ?: 'USD'))->unique();
                if ($currencies->count() > 1) {
                    throw new \RuntimeException('These sales are in '.$currencies->implode(', ')
                        .'. Pay each currency as its own run by selecting the rows.');
                }

                $total = round((float) $rows->sum('commission_amount'), 2);

                // The rate is frozen here, with the run. A statement reprinted
                // next year has to show the arithmetic that was performed, not
                // today's rate applied to last year's total.
                $live = app(ExchangeRateService::class)->current();
                $rate = isset($v['fx_rate']) && (float) $v['fx_rate'] > 0
                    ? (float) $v['fx_rate']
                    : $live['rate'];
                // A stale rate is still better than none, but it goes onto the
                // record saying so — this figure is what someone gets paid on.
                $source = isset($v['fx_rate']) && (float) $v['fx_rate'] > 0
                    ? 'entered at payout'
                    : $live['source'].(($live['stale'] ?? false) ? ' (stale)' : '');

                $payoutCurrency = (string) config('affiliates.payout_currency', 'NGN');

                $payout = AffiliatePayout::query()->create([
                    'affiliate_id' => $affiliate->getKey(),
                    'reference' => AffiliatePayout::nextReference($affiliate),
                    'period_start' => $rows->min('created_at')?->toDateString(),
                    'period_end' => $rows->max('created_at')?->toDateString(),
                    'sales_count' => $rows->count(),
                    'total_amount' => $total,
                    'currency' => $currencies->first(),

                    'payout_currency' => $payoutCurrency,
                    // Null rather than a guess when no rate could be had —
                    // an invented number here is one somebody gets paid on.
                    'payout_amount' => $rate ? round($total * $rate, 2) : null,
                    'fx_rate' => $rate,
                    'fx_source' => $rate ? $source : null,
                    'fx_captured_at' => $rate ? now() : null,

                    'status' => 'paid',
                    'method' => $v['method'] ?? null,
                    'payment_reference' => $v['payment_reference'] ?? null,
                    'note' => $v['note'] ?? null,
                    'paid_at' => now(),
                    'created_by_user_id' => $request->user()?->getKey(),
                ]);

                AffiliateConversion::query()->whereIn('id', $rows->pluck('id'))->update([
                    'payout_status' => 'paid',
                    'payout_id' => $payout->getKey(),
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

                return $payout;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => ['payout' => [
            'id' => $payout->getKey(),
            'reference' => $payout->reference,
            'sales_count' => (int) $payout->sales_count,
            'total_amount' => (float) $payout->total_amount,
            'payout_amount' => $payout->payout_amount !== null ? (float) $payout->payout_amount : null,
            'payout_currency' => $payout->payout_currency,
            'fx_rate' => $payout->fx_rate !== null ? (float) $payout->fx_rate : null,
        ]], 'meta' => []], 201);
    }

    /**
     * Undo a run that should not have been recorded — a mis-click, or a
     * transfer that failed. The sales return to outstanding; the run stays
     * visible as void, and its reference is never issued again.
     */
    public function voidPayout(Request $request, int $id, int $payoutId): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $payout = AffiliatePayout::query()->where('affiliate_id', $id)->findOrFail($payoutId);
        if ($payout->status === 'void') {
            return response()->json(['error' => ['message' => 'That payout is already void.']], 422);
        }

        DB::transaction(function () use ($payout, $v) {
            AffiliateConversion::query()->where('payout_id', $payout->getKey())->update([
                'payout_status' => 'unpaid',
                'payout_id' => null,
                'paid_at' => null,
                'updated_at' => now(),
            ]);
            $payout->fill(['status' => 'void', 'voided_at' => now(), 'void_reason' => $v['reason']])->save();
        });

        return response()->json(['data' => ['voided' => $payout->reference], 'meta' => []]);
    }

    /**
     * The statement an affiliate is sent.
     *
     * With a payout id it renders that run's membership, so re-downloading it
     * next year produces the same document. Without one it renders what is
     * outstanding now, as a preview of the next run.
     */
    public function statement(Request $request, int $id, ?int $payoutId = null): StreamedResponse
    {
        $affiliate = Affiliate::query()->findOrFail($id);
        $payout = $payoutId ? AffiliatePayout::query()->where('affiliate_id', $id)->findOrFail($payoutId) : null;
        $unpaidOnly = $payout ? false : $request->boolean('unpaid_only', false);

        $filename = Str::slug($affiliate->name).'-'
            .($payout ? Str::slug($payout->reference) : 'outstanding-'.now()->toDateString()).'.csv';

        return response()->streamDownload(function () use ($affiliate, $payout, $unpaidOnly) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Statement for', $affiliate->name]);
            if ($payout) {
                fputcsv($out, ['Payment reference', $payout->reference]);
                fputcsv($out, ['Paid', $payout->paid_at?->toDateString()]);
                fputcsv($out, ['Covering sales', $payout->period_start?->toDateString().' to '.$payout->period_end?->toDateString()]);
                if ($payout->status === 'void') {
                    fputcsv($out, ['VOID', $payout->void_reason]);
                }
            } else {
                fputcsv($out, ['Outstanding as at', now()->toDateString()]);
                fputcsv($out, ['Status', 'Not yet paid — this is a preview of the next payment.']);
            }
            fputcsv($out, []);

            // Gross and basis both appear: an affiliate who sees only the
            // commission cannot check it, and one who sees only the gross will
            // expect a percentage of a number that includes tax.
            fputcsv($out, [
                'Date', 'Order', 'Plan', 'Customer',
                'Customer paid (gross)', 'Commission basis', 'Basis method',
                'Currency', 'Rate %', 'Commission', 'Status',
            ]);

            $query = AffiliateConversion::query()->where('affiliate_id', $affiliate->getKey())->orderBy('created_at');
            if ($payout) {
                // The run's own membership, not a fresh query — that is the
                // whole point of recording it.
                $query->where('payout_id', $payout->getKey());
            } elseif ($unpaidOnly) {
                $query->where('payout_status', 'unpaid');
            }

            $total = 0.0;
            $methods = [];
            $query->chunk(200, function ($rows) use ($out, &$total, &$methods) {
                foreach ($rows as $c) {
                    $total += (float) $c->commission_amount;
                    $methods[$c->basis_method ?: 'gross'] = true;
                    fputcsv($out, [
                        $c->created_at?->toDateString(),
                        $c->order_id,
                        $c->plan,
                        // The buyer's address is the affiliate's evidence that a
                        // sale is real, and they already know they sent them.
                        $c->customer_email,
                        number_format((float) ($c->gross_amount ?: $c->order_amount), 2, '.', ''),
                        number_format((float) ($c->basis_amount ?: $c->order_amount), 2, '.', ''),
                        $c->basis_method ?: 'gross',
                        $c->currency,
                        number_format((float) $c->commission_percent, 2, '.', ''),
                        number_format((float) $c->commission_amount, 2, '.', ''),
                        $c->payout_status,
                    ]);
                }
            });

            fputcsv($out, []);
            fputcsv($out, ['', '', '', '', '', '', '', '', 'TOTAL', number_format($total, 2, '.', ''), '']);
            // States the assumption on the document itself, so nobody has to
            // reverse-engineer why the basis is lower than the gross.
            //
            // Whose fee it is has to be said out loud. "A platform fee" reads,
            // to the person being paid, as the platform paying them keeping a
            // slice — which is exactly the suspicion an unexplained deduction
            // earns. It is the merchant of record's, we never receive it, and
            // the document should be the thing that settles that.
            $cfg = (array) config('billing.affiliate_basis', []);
            fputcsv($out, []);

            if ($methods === ['provider_ex_tax' => true]) {
                // The only basis with no estimate anywhere in it.
                fputcsv($out, ['Commission basis: what the customer paid, less the sales tax remitted '
                    .'to their government. Taken from Kelviq\'s own figures for each order, not estimated.']);
            } elseif ($methods === ['provider_net' => true]) {
                // Tax is exact here; the fee is not, and saying otherwise would
                // overstate what we can actually show them.
                fputcsv($out, ['Commission basis: what the customer paid, less the sales tax remitted to '
                    .'their government — taken from Kelviq\'s own figures for each order — and Kelviq\'s '
                    .'payment processing fee, estimated at '
                    .round((float) ($cfg['platform_fee_percent'] ?? 0), 1).'%, which Kelviq does not report '
                    .'per order.']);
            } elseif ($methods === ['gross' => true] || $methods === ['provider_gross' => true]) {
                fputcsv($out, ['Commission basis: the full amount the customer paid. Nothing is deducted.']);
            } else {
                // Only name a deduction that was actually taken. Listing a
                // component at 0% describes a subtraction that did not happen,
                // which invites exactly the question the footer exists to
                // answer.
                $tax = round(((float) ($cfg['tax_rate_estimate'] ?? 0)) * 100, 1);
                $fee = round((float) ($cfg['platform_fee_percent'] ?? 0), 1);

                $parts = [];
                if ($tax > 0) {
                    $parts[] = 'sales tax (estimated at '.$tax.'%, remitted to the customer\'s government)';
                }
                if ($fee > 0 && ($cfg['method'] ?? 'net') === 'net') {
                    $parts[] = 'Kelviq\'s payment processing fee (estimated at '.$fee.'%)';
                }

                fputcsv($out, [$parts === []
                    ? 'Commission basis: the full amount the customer paid. Nothing is deducted.'
                    : 'Commission basis: what the customer paid, less '.implode(' and ', $parts).'.']);
            }

            fputcsv($out, ['Kelviq is our merchant of record and collects the payment. '
                .'WyvStudio deducts nothing of its own before your commission.']);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
