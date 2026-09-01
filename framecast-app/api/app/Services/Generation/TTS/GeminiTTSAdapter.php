<?php

namespace App\Services\Generation\TTS;

use App\Services\ApiUsageService;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Gemini 3.1 Flash TTS via Replicate — the default expressive voice engine.
 *
 * Style is controlled in natural language the way Gemini TTS is designed for:
 * a voice-direction line ("Speak in a warm, upbeat tone") is prepended to the
 * script as "<direction>: <script>", and inline performance [tags] the user
 * writes inside the script (e.g. "[laughs]", "[whispering]") are passed
 * through untouched. The `voice` input picks one of the 30 prebuilt voices.
 */
class GeminiTTSAdapter implements TTSAdapter
{
    private const MAX_POLL_ITERATIONS = 60;
    private const POLL_INTERVAL_SEC = 2;

    public function __construct(
        private readonly ApiUsageService $usage,
    ) {
    }

    /**
     * Shape a free-text voice direction into the imperative form this model
     * expects.
     *
     * `prompt` is a style field whose own default is "Say the following." and
     * whose documented examples are imperative ("Say this in a calm,
     * professional tone"). A bare descriptive fragment — "Calm, soothing, and
     * relaxed — gentle and reassuring." — reads as content instead, and the
     * model intermittently SPEAKS it ahead of the script. Measured on real
     * production audio: 4 of 13 scenes, all sharing one direction, had the
     * direction read aloud before the line.
     *
     * Wrapping it in an imperative frame removes that ambiguity. Directions
     * the user already phrased as an instruction are left untouched.
     */
    public static function asStyleInstruction(string $direction): string
    {
        $d = trim($direction);

        if ($d === '') {
            return '';
        }

        // Already an instruction — respect the user's phrasing.
        if (preg_match('/^(say|speak|read|narrate|deliver|talk|use|sound|perform|voice|make)\b/i', $d)) {
            return $d;
        }

        $d = rtrim($d, " \t\n\r\0\x0B.!,;:");

        if ($d === '') {
            return '';
        }

        // Lower the first letter so it reads as one sentence — but leave an
        // all-caps opening word alone ("NASA mission control" must not become
        // "nASA mission control").
        $firstWord = preg_split('/\s+/', $d)[0] ?? '';
        $isAcronym = mb_strlen($firstWord) > 1 && mb_strtoupper($firstWord) === $firstWord;
        if ($firstWord === '' || ! $isAcronym) {
            $d = mb_strtolower(mb_substr($d, 0, 1)).mb_substr($d, 1);
        }

        return "Say the following in a manner that is {$d}.";
    }

    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0, array $options = []): array
    {
        $apiToken = (string) config('services.replicate.api_token');
        $voice = GeminiVoices::resolve($voiceId);
        $usageContext = $this->usage->contextFromOptions($options);
        $model = (string) config('services.gemini_tts.model', 'google/gemini-3.1-flash-tts');

        // Voice direction goes in the model's DEDICATED `prompt` (style)
        // input — NOT mixed into `text`, or the model speaks the direction
        // aloud. Inline [tags] stay in the script text.
        $direction = trim((string) ($options['voice_prompt'] ?? ''));
        $script = trim($text) !== '' ? $text : ' ';

        if ($apiToken === '') {
            // No key configured (local/dev) — return a deterministic stub so the
            // pipeline still completes instead of hard-failing.
            $seed = rawurlencode(Str::slug(mb_substr($text, 0, 40)).'-'.Str::random(6));
            return [
                'audio_url' => "https://example.com/audio/{$seed}.mp3",
                'duration_seconds' => $this->estimateDuration($text, $speed),
                'provider_key' => 'replicate:'.$model,
                'provider_voice_id' => $voice,
            ];
        }

        $url = 'https://api.replicate.com/v1/models/'.$model.'/predictions';
        $input = ['text' => $script, 'voice' => $voice];
        if ($direction !== '') {
            $input['prompt'] = self::asStyleInstruction($direction);
        }
        if (($ver = (string) config('services.gemini_tts.version', '')) !== '') {
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
                throw new RuntimeException("gemini-tts failed to start ({$start->status()}): {$start->body()}");
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
                    throw new RuntimeException("gemini-tts {$status}: ".($prediction['error'] ?? 'unknown'));
                }
                sleep(self::POLL_INTERVAL_SEC);
                if (! $id) {
                    break;
                }
                $prediction = Http::withToken($apiToken)->timeout(30)
                    ->get("https://api.replicate.com/v1/predictions/{$id}")->json();
            }

            if (! $audioUrl) {
                throw new RuntimeException('gemini-tts produced no audio URL within the poll window.');
            }

            $bytes = Http::timeout(120)->get($audioUrl)->body();
            if ($bytes === '') {
                throw new RuntimeException('gemini-tts returned an audio URL but the file was empty.');
            }

            // Gemini TTS emits WAV; keep the extension/content-type honest.
            $isWav = str_contains(strtolower((string) $audioUrl), '.wav');
            $ext = $isWav ? 'wav' : 'mp3';

            // The model accepts no pace parameter, so the speed slider was a
            // silent no-op for every expressive voice. Apply it ourselves,
            // pitch-preserved; the duration measured below reflects it.
            $bytes = \App\Services\Media\AudioTempo::apply($bytes, $ext, $speed);

            $path = 'audio/tts/'.Str::uuid().'.'.$ext;
            $audioStorageUrl = app(StorageService::class)->put($path, $bytes, [
                'ContentType' => $isWav ? 'audio/wav' : 'audio/mpeg',
            ]);

            // MEASURE the real duration while the bytes are in hand. The
            // word-count estimate this used to return drifts from the actual
            // audio by seconds, and two things depend on the stored number
            // being true: the editor's full preview (advanced scenes before
            // their narration finished) and spokesperson billing (length
            // buckets keyed off it). Exports were immune only because the
            // renderer re-probes the file itself.
            $measured = null;
            rescue(function () use ($bytes, $ext, &$measured): void {
                $tmp = tempnam(sys_get_temp_dir(), 'tts-').'.'.$ext;
                file_put_contents($tmp, $bytes);
                $out = [];
                exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '.escapeshellarg($tmp), $out);
                @unlink($tmp);
                $d = isset($out[0]) ? (float) $out[0] : 0.0;
                if ($d > 0.05) {
                    $measured = $d;
                }
            }, report: false);
        } catch (Throwable $exception) {
            $this->usage->record([
                ...$usageContext,
                'provider' => 'replicate',
                'service' => 'tts',
                'operation' => 'speech',
                'model' => $model,
                'status' => 'failed',
                'units' => mb_strlen($text),
                'estimated_cost_usd' => $this->estimateCost(trim($script.' '.$direction)),
                'error_code' => 'gemini_tts_error',
                'error_message' => $exception->getMessage(),
            ]);
            throw new RuntimeException('Gemini voice generation failed: '.$exception->getMessage(), previous: $exception);
        }

        $this->usage->record([
            ...$usageContext,
            'provider' => 'replicate',
            'service' => 'tts',
            'operation' => 'speech',
            'model' => $model,
            'status' => 'succeeded',
            'units' => mb_strlen($text),
            'estimated_cost_usd' => $this->estimateCost(trim($script.' '.$direction)),
            'metadata_json' => [
                ...($usageContext['metadata_json'] ?? []),
                'voice_id' => $voice,
                'language' => $language,
                'has_direction' => $direction !== '',
            ],
        ]);

        return [
            'audio_url' => $audioStorageUrl,
            'duration_seconds' => $measured ?? $this->estimateDuration($text, $speed),
            'provider_key' => 'replicate:'.$model,
            'provider_voice_id' => $voice,
        ];
    }

    /**
     * Rough upstream COGS from the token pricing ($2/1M input, $0.04/1K
     * output). Input ≈ prompt tokens (~chars/4); output audio ≈ ~16 tokens per
     * spoken word. Best-estimate only — the real figure is recorded per call.
     */
    private function estimateCost(string $prompt): float
    {
        $inputTokens = (int) ceil(mb_strlen($prompt) / 4);
        $words = max(1, count(preg_split('/\s+/', trim($prompt)) ?: []));
        $outputTokens = $words * 16;

        return round(($inputTokens * 0.000002) + ($outputTokens * 0.00004), 6);
    }

    private function estimateDuration(string $text, float $speed): float
    {
        $wordCount = max(1, count(preg_split('/\s+/', trim($text)) ?: []));
        $wpm = max(80.0, 150.0 * max(0.5, min(2.0, $speed)));
        $seconds = ($wordCount / $wpm) * 60.0;

        return max(2.0, round($seconds, 2));
    }
}
