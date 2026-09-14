<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Managing affiliates and paying them.
 *
 * Commission is read from the conversion rows, never recalculated from the
 * affiliate's current rate — a renegotiated percentage must not quietly
 * restate what is owed on sales already made.
 */
class AffiliateController extends Controller
{
    public function index(): JsonResponse
    {
        $affiliates = Affiliate::query()->withCount('clicks')->orderBy('name')->get()->map(function (Affiliate $a) {
            $rows = AffiliateConversion::query()->where('affiliate_id', $a->getKey())->get();

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
                'owed'    => round((float) $rows->where('payout_status', 'unpaid')->sum('commission_amount'), 2),
                'paid'    => round((float) $rows->where('payout_status', 'paid')->sum('commission_amount'), 2),
            ];
        });

        return response()->json(['data' => ['affiliates' => $affiliates], 'meta' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'code'  => ['nullable', 'string', 'max:32', 'alpha_dash', 'unique:affiliates,code'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // A readable code beats a random one: an affiliate has to type it into
        // their own posts, and a typo is a lost sale.
        $code = $v['code'] ?? Str::lower(Str::slug($v['name']).'-'.Str::random(4));

        $affiliate = Affiliate::query()->create([
            'code' => $code,
            'name' => $v['name'],
            'email' => $v['email'] ?? null,
            'commission_percent' => $v['commission_percent'],
            'notes' => $v['notes'] ?? null,
            'status' => 'active',
        ]);

        return response()->json(['data' => ['affiliate' => $affiliate], 'meta' => []], 201);
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

    public function conversions(int $id): JsonResponse
    {
        $rows = AffiliateConversion::query()->where('affiliate_id', $id)
            ->orderByDesc('created_at')->limit(500)->get()
            ->map(fn (AffiliateConversion $c) => [
                'id' => $c->getKey(),
                'date' => $c->created_at?->toDateString(),
                'customer_email' => $c->customer_email,
                'order_id' => $c->order_id,
                'plan' => $c->plan,
                'order_amount' => (float) $c->order_amount,
                'commission_percent' => (float) $c->commission_percent,
                'commission_amount' => (float) $c->commission_amount,
                'attribution_source' => $c->attribution_source,
                'payout_status' => $c->payout_status,
            ]);

        return response()->json(['data' => ['conversions' => $rows], 'meta' => []]);
    }

    /**
     * The statement an affiliate is sent. Streamed so a long history doesn't
     * have to be held in memory.
     */
    public function statement(Request $request, int $id): StreamedResponse
    {
        $affiliate = Affiliate::query()->findOrFail($id);
        $unpaidOnly = $request->boolean('unpaid_only', false);

        $filename = Str::slug($affiliate->name).'-statement-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($affiliate, $unpaidOnly) {
            $out = fopen('php://output', 'w');
            // Gross and basis both appear: an affiliate who sees only the
            // commission cannot check it, and one who sees only the gross will
            // expect a percentage of a number that includes tax.
            fputcsv($out, [
                'Date', 'Order', 'Plan', 'Customer',
                'Customer paid (gross)', 'Commission basis', 'Basis method',
                'Currency', 'Rate %', 'Commission', 'Status',
            ]);

            $query = AffiliateConversion::query()->where('affiliate_id', $affiliate->getKey())->orderBy('created_at');
            if ($unpaidOnly) {
                $query->where('payout_status', 'unpaid');
            }

            $total = 0.0;
            $query->chunk(200, function ($rows) use ($out, &$total) {
                foreach ($rows as $c) {
                    $total += (float) $c->commission_amount;
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
            $cfg = (array) config('billing.affiliate_basis', []);
            fputcsv($out, []);
            fputcsv($out, ['Basis: gross less estimated tax of '
                .round(((float) ($cfg['tax_rate_estimate'] ?? 0)) * 100, 1).'% and a platform fee of '
                .round((float) ($cfg['platform_fee_percent'] ?? 0), 1).'%.']);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Settle what is currently outstanding. */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['conversion_ids' => ['sometimes', 'array'], 'conversion_ids.*' => ['integer']]);

        $query = AffiliateConversion::query()->where('affiliate_id', $id)->where('payout_status', 'unpaid');
        if (! empty($v['conversion_ids'])) {
            $query->whereIn('id', $v['conversion_ids']);
        }

        $count = $query->update(['payout_status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['marked_paid' => $count], 'meta' => []]);
    }
}
