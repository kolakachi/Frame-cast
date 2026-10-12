<?php

namespace App\Services\Ugc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Follow the take, not the plan (owner, 2026-10-12): after a clip is made, the director's model reads how it really
 * went (what was said, its last frame, the reviewer's answers) and, when it broadly kept to its plan, rewrites the next
 * clip's timed sheet to continue from there: the first frame as the clip really ended, the energy of the delivery,
 * small action details. The words, the camera, the product, the clothes, the place and the order of actions stay. A
 * clip that went off its plan is not followed: that would carry the mistake on. In "record" mode (services.ugc.follow_on)
 * the rewrite is only kept in the take's trace; in "apply" mode it replaces the next clip's sheet.
 */
class UgcFollowOn
{
    private const PROMPT = <<<'TXT'
You continue a short creator video that a video model films clip by clip. A clip was just made. Below are its plan, the words heard in it, and its last frame (the image), then the plan for the next clip.

1. close: did the clip broadly keep to its plan (its line was said and its main action done)? Small differences in wording, gesture or framing still count as close.
2. ended: how it really ended, as seen in the last frame: what the left hand and the right hand hold and how they grip it, the product's state and place, the body (sitting, standing or leaning and on what, legs and feet, which way the shoulders face, the head's turn and tilt, distance from the lens and place in the frame), the camera (handheld selfie, or standing or leaned against something), the face and mood, and the background as it is (curtains open or closed, lights on or off, the light, objects on the surfaces).
3. next: only when close, the next clip's timed sheet rewritten to continue from how this clip really ended: its first frame matches the last frame, with each object in the same hand and grip, the body in the same posture and place in the frame, and the background exactly as it is (the same curtains, light and objects), the energy follows the delivery, small action details adjust to where things really are. Keep exactly: its words ("says", in order, all of them, nothing added), its camera, the product, the clothes, the place and the order of its actions. Same fields as the plan. When not close, next is null.

Answer JSON only: {"close": true or false, "why": "one sentence", "ended": {"left": "", "right": "", "product": "", "body": "", "camera": "", "face": "", "background": ""}, "next": {"camera": "", "shot": "", "start": "", "blocks": [{"when": "00:00-00:02", "view": "", "left": "", "right": "", "product": "", "face": "", "says": "", "sound": "", "check": ""}], "end": ""} or null}
TXT;

    /**
     * @param  array<string, mixed>  $chunk  the clip just made (with its sheet and line)
     * @param  array<string, mixed>|null  $heard  its transcript
     * @param  array<string, mixed>  $next  the next clip (with its sheet and line, after the carry-over)
     * @return array{close: bool, why: string, ended: array<string, string>, next: array<string, mixed>|null, valid: bool}|null
     */
    public function follow(array $chunk, ?array $heard, string $clipPath, ?array $verdict, array $next): ?array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '' || empty($chunk['sheet']) || empty($next['sheet'])) return null;
        $frame = $this->lastFrame($clipPath);
        if ($frame === null) return null;
        $text = self::PROMPT
            ."\n\nThe clip's plan: ".json_encode($chunk['sheet'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\nWords heard in it: \"".trim((string) ($heard['transcript'] ?? '(no transcript)')).'"'
            .($verdict !== null ? "\nA checker's yes/no answers about it: ".json_encode($verdict['answers'] ?? [], JSON_UNESCAPED_UNICODE) : '')
            ."\n\nThe next clip's plan: ".json_encode($next['sheet'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\nThe next clip's words: \"".trim((string) ($next['dialogue'] ?? '')).'"';
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(90)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('services.ai.direct_model', 'claude-sonnet-5-5'),
                    'max_tokens' => 4000, 'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => $text],
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($frame)]],
                    ]]]]);
            if (! $r->successful()) {
                Log::warning('ugc follow-on: the call failed', ['status' => $r->status()]);
                return null;
            }
            $answer = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        } catch (\Throwable $e) {
            Log::warning('ugc follow-on: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }

        return self::parse($answer, $next);
    }

    /** The answer, with its rewrite checked: one sheet, the next clip's exact words, its camera unchanged. */
    public static function parse(string $answer, array $next): ?array
    {
        $a = strpos($answer, '{'); $b = strrpos($answer, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($answer, $a, $b - $a + 1), true) : null;
        if (! is_array($json) || ! is_bool($json['close'] ?? null)) return null;
        $sheet = null;
        if ($json['close'] && is_array($json['next'] ?? null)) {
            $json['next']['camera'] = $next['sheet']['camera'] ?? ($json['next']['camera'] ?? '');
            $sheets = UgcActionSheet::parse(json_encode(['clips' => [$json['next']]]), 1);
            $sheet = $sheets[0] ?? null;
        }
        $valid = $sheet !== null && UgcActionSheet::faults([$sheet], [$next]) === [];

        return ['close' => $json['close'], 'why' => mb_substr(trim((string) ($json['why'] ?? '')), 0, 300),
            'ended' => array_map(fn ($v) => mb_substr(trim((string) $v), 0, 200), (array) ($json['ended'] ?? [])), 'next' => $sheet, 'valid' => $valid];
    }

    private function lastFrame(string $clipPath): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'follow-').'.jpg';
        Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-sseof', '-0.3', '-i', $clipPath, '-update', '1', '-vf', 'scale=720:-2', '-q:v', '3', $file]);
        $bytes = is_file($file) ? (string) file_get_contents($file) : '';
        @unlink($file);

        return $bytes !== '' ? $bytes : null;
    }
}
