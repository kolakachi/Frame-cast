<?php

namespace App\Services\Generation\TTS;

use App\Services\ApiUsageService;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Chatterbox (Resemble AI) via Replicate — zero-shot voice cloning.
 *
 * There's no trained voice id: every synthesis sends the user's reference
 * sample as `audio_prompt`, so the caller MUST pass options['clone_audio_url']
 * (a public/signed URL of the cloned VoiceProfile's source sample). Without it
 * Chatterbox falls back to its built-in default voice — which is not what a
 * cloned voice wants, so we require it.
 */
class ChatterboxTTSAdapter implements TTSAdapter
{
    private const MAX_POLL_ITERATIONS = 60;
    private const POLL_INTERVAL_SEC = 2;

    public function __construct(
        private readonly ApiUsageService $usage,
    ) {
    }

    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0, array $options = []): array
    {
        $apiToken = (string) config('services.replicate.api_token');
        $model = (string) config('services.chatterbox.model', 'resemble-ai/chatterbox');
        $usageContext = $this->usage->contextFromOptions($options);
        $cloneUrl = trim((string) ($options['clone_audio_url'] ?? ''));

        if ($apiToken === '') {
            $seed = rawurlencode(Str::slug(mb_substr($text, 0, 40)).'-'.Str::random(6));
            return [
                'audio_url' => "https://example.com/audio/{$seed}.wav",
                'duration_seconds' => $this->estimateDuration($text, $speed),
                'provider_key' => 'replicate:'.$model,
                'provider_voice_id' => $voiceId ?: 'clone',
            ];
        }

        if ($cloneUrl === '') {
            throw new RuntimeException('Cloned voice is missing its reference sample.');
        }

        $input = [
            'prompt'       => trim($text) !== '' ? $text : ' ',
            'audio_prompt' => $cloneUrl,
            'exaggeration' => (float) config('services.chatterbox.exaggeration', 0.5),
            'cfg_weight'   => (float) config('services.chatterbox.cfg_weight', 0.5),
        ];

        $url = 'https://api.replicate.com/v1/models/'.$model.'/predictions';
        if (($ver = (string) config('services.chatterbox.version', '')) !== '') {
            $url = 'https://api.replicate.com/v1/predictions';
            $body = ['version' => $ver, 'input' => $input];
        } else {
            $body = ['input' => $input];
        }

        try {
            $start = Http::withToken($apiToken)
                ->withHeaders(['Prefer' => 'wait=10'])
                ->timeout(30)
                ->post($url, $body);

            if (! $start->successful()) {
                throw new RuntimeException("chatterbox failed to start ({$start->status()}): {$start->body()}");
            }

            $prediction = $start->json();
            $id = $prediction['id'] ?? null;
            $audioUrl = null;

            for ($i = 0; $i < self::MAX_POLL_ITERATIONS; $i++) {
                $status = $prediction['status'] ?? null;
                if ($status === 'succeeded') {
                    $output = $prediction['output'] ?? null;
                    $audioUrl = is_array($output) ? ($output[0] ?? null) : $output;
                    break;
                }
                if (in_array($status, ['failed', 'canceled'], true)) {
                    throw new RuntimeException("chatterbox {$status}: ".($prediction['error'] ?? 'unknown'));
                }
                sleep(self::POLL_INTERVAL_SEC);
                if (! $id) {
                    break;
                }
                $prediction = Http::withToken($apiToken)->timeout(30)
                    ->get("https://api.replicate.com/v1/predictions/{$id}")->json();
            }

            if (! $audioUrl) {
                throw new RuntimeException('chatterbox produced no audio URL within the poll window.');
            }

            $bytes = Http::timeout(120)->get($audioUrl)->body();
            if ($bytes === '') {
                throw new RuntimeException('chatterbox returned an audio URL but the file was empty.');
            }

            $isWav = str_contains(strtolower((string) $audioUrl), '.wav');
            $ext = $isWav ? 'wav' : 'mp3';
            $path = 'audio/tts/'.Str::uuid().'.'.$ext;
            $audioStorageUrl = app(StorageService::class)->put($path, $bytes, [
                'ContentType' => $isWav ? 'audio/wav' : 'audio/mpeg',
            ]);
        } catch (Throwable $exception) {
            $this->usage->record([
                ...$usageContext,
                'provider' => 'replicate',
                'service' => 'tts',
                'operation' => 'voice_clone_speech',
                'model' => $model,
                'status' => 'failed',
                'units' => mb_strlen($text),
                'estimated_cost_usd' => 0.009,
                'error_code' => 'chatterbox_error',
                'error_message' => $exception->getMessage(),
            ]);
            throw new RuntimeException('Cloned voice generation failed: '.$exception->getMessage(), previous: $exception);
        }

        $this->usage->record([
            ...$usageContext,
            'provider' => 'replicate',
            'service' => 'tts',
            'operation' => 'voice_clone_speech',
            'model' => $model,
            'status' => 'succeeded',
            'units' => mb_strlen($text),
            'estimated_cost_usd' => 0.009,
            'metadata_json' => [
                ...($usageContext['metadata_json'] ?? []),
                'voice_id' => $voiceId,
                'language' => $language,
                'cloned' => true,
            ],
        ]);

        return [
            'audio_url' => $audioStorageUrl,
            'duration_seconds' => $this->estimateDuration($text, $speed),
            'provider_key' => 'replicate:'.$model,
            'provider_voice_id' => $voiceId ?: 'clone',
        ];
    }

    private function estimateDuration(string $text, float $speed): float
    {
        $wordCount = max(1, count(preg_split('/\s+/', trim($text)) ?: []));
        $wpm = max(80.0, 150.0 * max(0.5, min(2.0, $speed)));
        $seconds = ($wordCount / $wpm) * 60.0;

        return max(2.0, round($seconds, 2));
    }
}
