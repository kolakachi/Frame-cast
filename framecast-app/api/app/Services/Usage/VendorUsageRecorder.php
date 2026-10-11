<?php

namespace App\Services\Usage;

use App\Services\ApiUsageService;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The real cost of every finished Replicate prediction and every Claude reply, recorded in api_usage_events
 * (owner, 2026-10-11: "we need to know how to go about it"). One HTTP client middleware covers every call site, old
 * and new, so no path can forget to record. The workspace and project come from the request or job context
 * (UsageContext); prices from config/pricing.php and config/create.php. Telemetry never breaks the call it measures.
 */
class VendorUsageRecorder
{
    /** A Guzzle middleware for Http::globalMiddleware(). */
    public static function middleware(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request) {
                    rescue(fn () => self::observe($request, $response), null, false);
                    return $response;
                });
            };
        };
    }

    public static function observe(RequestInterface $request, ResponseInterface $response): void
    {
        $host = $request->getUri()->getHost();
        $path = $request->getUri()->getPath();
        if ($host === 'api.replicate.com' && str_contains($path, '/predictions')) {
            $p = self::json($response);
            if ($p) self::replicate($p);
        } elseif ($host === 'api.anthropic.com' && $path === '/v1/messages' && $response->getStatusCode() < 300) {
            $m = self::json($response);
            if ($m) self::anthropic($m);
        }
    }

    /** A finished prediction, recorded once however many times it is polled. */
    public static function replicate(array $p): void
    {
        $status = (string) ($p['status'] ?? '');
        $id = (string) ($p['id'] ?? '');
        $model = (string) ($p['model'] ?? '');
        if ($id === '' || $model === '' || ! in_array($status, ['succeeded', 'failed', 'canceled'], true)) return;
        if (in_array($model, (array) config('pricing.replicate_self_recorded', []), true)) return;
        if (! Cache::add('vendor-usage:replicate:'.$id, 1, now()->addDays(3))) return;

        [$usd, $units, $basis] = $status === 'succeeded' ? self::replicateUsd($model, $p) : [0.0, 0, 'not_billed'];
        $metrics = (array) ($p['metrics'] ?? []);
        app(ApiUsageService::class)->record(UsageContext::current() + [
            'provider' => 'replicate',
            'service' => self::service($model, $basis, $p),
            'operation' => 'prediction',
            'model' => $model,
            'status' => $status === 'succeeded' ? 'succeeded' : 'failed',
            'units' => (int) round($units),
            'estimated_cost_usd' => $usd,
            'error_message' => $status === 'succeeded' ? null : mb_substr((string) ($p['error'] ?? $status), 0, 300),
            'metadata_json' => array_filter(['prediction_id' => $id, 'price_basis' => $basis, 'predict_time' => $metrics['predict_time'] ?? null,
                'output_seconds' => $metrics['video_output_duration_seconds'] ?? null, 'resolution' => $p['input']['resolution'] ?? null], fn ($v) => $v !== null),
        ]);
    }

    /**
     * What a succeeded prediction cost: [dollars, units, how it was priced].
     *
     * @return array{0: float, 1: float, 2: string}
     */
    public static function replicateUsd(string $model, array $p): array
    {
        $price = ((array) config('pricing.replicate', []))[$model] ?? null; // not a dotted key: model names contain dots (gemini-omni-1.1)
        $input = (array) ($p['input'] ?? []);
        $metrics = (array) ($p['metrics'] ?? []);
        $pick = function ($rate) use ($price, $input) {
            if (! is_array($rate)) return (float) $rate;
            $key = (string) ($input[$price['by'] ?? 'resolution'] ?? '');
            return (float) ($rate[$key] ?? $rate[$price['default'] ?? ''] ?? reset($rate));
        };
        if (is_array($price) && isset($price['per_second'])) {
            $seconds = (float) ($metrics['video_output_duration_seconds'] ?? $input['duration'] ?? $input['seconds'] ?? 0);
            $rate = ($input['generate_audio'] ?? true) === false && isset($price['no_audio']) ? (float) $price['no_audio'] : $pick($price['per_second']);
            return [round($seconds * $rate, 6), $seconds, 'per_second'];
        }
        if (is_array($price) && isset($price['per_image'])) {
            $out = $p['output'] ?? null;
            $n = is_array($out) ? max(1, count($out)) : 1;
            return [round($n * (float) $price['per_image'], 6), $n, 'per_image'];
        }
        if (is_array($price) && isset($price['per_run'])) {
            return [round($pick($price['per_run']), 6), 1, 'per_run'];
        }
        $t = (float) ($metrics['predict_time'] ?? 0);
        return [round($t * (float) config('pricing.replicate_time_rate', 0.000725), 6), $t, 'predict_time_estimate'];
    }

    private static function service(string $model, string $basis, array $p): string
    {
        if ($basis === 'per_image') return 'image';
        if (isset($p['metrics']['video_output_duration_seconds']) || preg_match('/veo|omni|seedance|wan-|hailuo|kling|fabric|modify-video|aleph/', $model)) return 'video';
        if (preg_match('/music|audio|tts|chatterbox/', $model)) return 'audio';
        return 'other';
    }

    /** A Claude reply, priced from its token counts. */
    public static function anthropic(array $m): void
    {
        $model = (string) ($m['model'] ?? '');
        $u = (array) ($m['usage'] ?? []);
        if ($model === '' || ! $u) return;
        $in = (int) ($u['input_tokens'] ?? 0);
        $out = (int) ($u['output_tokens'] ?? 0);
        $write = (int) ($u['cache_creation_input_tokens'] ?? 0);
        $read = (int) ($u['cache_read_input_tokens'] ?? 0);
        $r = self::claudeRates($model, $in + $write + $read);
        app(ApiUsageService::class)->record(UsageContext::current() + [
            'provider' => 'anthropic',
            'service' => 'text',
            'operation' => 'message',
            'model' => $model,
            'status' => 'succeeded',
            'prompt_tokens' => $in + $write + $read,
            'completion_tokens' => $out,
            'total_tokens' => $in + $write + $read + $out,
            // Rates are micro-dollars a token.
            'estimated_cost_usd' => round(($in * $r['input'] + $out * $r['output'] + $write * ($r['cache_write'] ?? $r['input']) + $read * ($r['cache_read'] ?? $r['input'])) / 1e6, 6),
            'metadata_json' => array_filter(['message_id' => $m['id'] ?? null, 'cache_write' => $write ?: null, 'cache_read' => $read ?: null]),
        ]);
    }

    /** @return array{input: float, output: float, cache_write?: float, cache_read?: float} */
    public static function claudeRates(string $model, int $promptTokens): array
    {
        $rates = (array) config('create.model_rates', []);
        $r = $rates[$model] ?? null;
        if (! $r) {
            $r = str_contains($model, 'opus') ? (array) config('create.anthropic_rates') : ($rates['claude-haiku-4-5-20251001'] ?? ['input' => 1, 'output' => 5]);
        }
        if (isset($r['long_above'], $r['long']) && $promptTokens > (int) $r['long_above']) $r = $r['long'];
        return $r;
    }

    private static function json(ResponseInterface $response): ?array
    {
        if (! str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')) return null;
        $body = $response->getBody();
        $text = (string) $body;
        if ($body->isSeekable()) $body->rewind();
        $data = json_decode($text, true);
        return is_array($data) ? $data : null;
    }
}
