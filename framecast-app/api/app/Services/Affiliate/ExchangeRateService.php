<?php

namespace App\Services\Affiliate;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What a dollar of commission is worth in naira.
 *
 * The published mid-market rate is not what a transfer into a Nigerian account
 * achieves, so quoting it unadjusted would promise an affiliate a figure we
 * cannot hit and leave the shortfall to be discovered at payout. Everything
 * shown is mid-market less a configured spread.
 *
 * Nothing here is authoritative for money. A payout records the rate it was
 * actually settled at; this exists so an affiliate can see roughly what they
 * are owed before that happens.
 */
class ExchangeRateService
{
    private const CACHE_KEY = 'affiliate:fx:usd-ngn';

    /**
     * @return array{rate: float|null, mid: float|null, pair: string, source: string,
     *               spread_percent: float, fetched_at: string|null, stale: bool}
     */
    public function current(): array
    {
        $from = strtoupper((string) config('affiliates.commission_currency', 'USD'));
        $to = strtoupper((string) config('affiliates.payout_currency', 'NGN'));
        $spread = max(0.0, min(50.0, (float) config('affiliates.fx.spread_percent', 0)));

        // Note the array union operator below keeps the LEFT operand on a key
        // collision, so anything a branch needs to override must not be set
        // here. `stale` was, and a fallback to the cached rate reported itself
        // as fresh.
        $base = [
            'pair' => "{$from}/{$to}",
            'spread_percent' => $spread,
        ];

        // A pinned rate skips the network entirely — the escape hatch for a
        // week where the published number bears no relation to reality.
        $override = config('affiliates.fx.override');
        if ($override !== null && (float) $override > 0) {
            return $base + [
                'rate' => round((float) $override, 6),
                'mid' => null,
                'source' => 'manual override',
                'fetched_at' => null,
                'stale' => false,
            ];
        }

        $fetched = $this->fetchMid($from, $to);

        if (! $fetched) {
            return $base + [
                'rate' => null, 'mid' => null,
                'source' => 'unavailable', 'fetched_at' => null, 'stale' => true,
            ];
        }

        return $base + [
            'mid' => round($fetched['mid'], 6),
            'rate' => round($fetched['mid'] * (1 - $spread / 100), 6),
            'source' => $fetched['source'],
            'fetched_at' => $fetched['fetched_at'],
            'stale' => $fetched['stale'],
        ];
    }

    /** The number to multiply a USD commission by, or null when unknown. */
    public function rate(): ?float
    {
        return $this->current()['rate'];
    }

    /** @return array{mid: float, source: string, fetched_at: string, stale: bool}|null */
    private function fetchMid(string $from, string $to): ?array
    {
        $minutes = max(1, (int) config('affiliates.fx.cache_minutes', 180));

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && ($cached['mid'] ?? 0) > 0
            && ($cached['from'] ?? '') === $from && ($cached['to'] ?? '') === $to
            && strtotime($cached['fetched_at']) > time() - $minutes * 60) {
            return ['mid' => (float) $cached['mid'], 'source' => $cached['source'],
                'fetched_at' => $cached['fetched_at'], 'stale' => false];
        }

        try {
            $endpoint = rtrim((string) config('affiliates.fx.endpoint'), '/').'/'.$from;
            $response = Http::timeout(8)->get($endpoint);

            $mid = (float) ($response->json("rates.{$to}") ?? 0);
            if ($response->successful() && $mid > 0) {
                // The feed's own timestamp, not ours: it republishes daily, so
                // saying when we asked would overstate how fresh the rate is.
                $published = $response->json('time_last_update_utc');
                $fresh = [
                    'mid' => $mid, 'from' => $from, 'to' => $to,
                    'source' => 'open.er-api.com',
                    'fetched_at' => $published
                        ? \Carbon\CarbonImmutable::parse($published)->toIso8601String()
                        : now()->toIso8601String(),
                ];
                // Held well past its useful life so an outage degrades to a
                // stale number rather than to nothing.
                Cache::put(self::CACHE_KEY, $fresh, now()->addDays(7));

                return $fresh + ['stale' => false];
            }

            Log::info('ExchangeRateService: rate unavailable', ['status' => $response->status()]);
        } catch (\Throwable $e) {
            Log::info('ExchangeRateService: lookup failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }

        // An FX outage must never take a dashboard down. Last known rate,
        // labelled as such, beats an error page.
        if (is_array($cached) && ($cached['mid'] ?? 0) > 0) {
            return ['mid' => (float) $cached['mid'], 'source' => $cached['source'],
                'fetched_at' => $cached['fetched_at'], 'stale' => true];
        }

        return null;
    }
}
