<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MOD-RETRY (owner, 2026-10-16: "inform the user / recommend a different message"): when a model's moderation refuses
 * something we could not recover by ourselves, the user is told in plain words what likely tripped it and is given a
 * reworded version to send. The wording keeps what the user asked for (the action, product, people, place, any spoken
 * line) and only swaps words a cautious filter misreads. Null when no advice could be made: the refusal is then shown
 * as before. One small call on the check model.
 */
class RefusalAdvice
{
    /** @return array{why: string, wording: string}|null */
    public static function for(string $what, string $text): ?array
    {
        $text = trim($text);
        $key = (string) config('services.anthropic.key');
        if ($text === '' || $key === '') return null;
        $ask = "A video ad tool asked an AI model to make {$what} and the model's content filter refused it. The request was for a "
            ."tasteful, ordinary product ad. Filters judge what the picture would show as much as the words: bare skin, undressing, "
            ."beds and intimacy, children unclothed or in a bath, injury, weapons, gambling wins, alcohol, medical or weight-loss "
            ."claims. Explain to the user, in one short plain sentence, what most likely tripped it (the picture or the words). Then "
            ."reword it so a cautious filter accepts it: keep the product, the people, the place and the framing, and any quoted "
            ."spoken line word for word, unless that is the problem; change only the risky element; add nothing; keep the same "
            ."language as the original.\n"
            .'Reply with JSON only: {"why": "<one sentence>", "wording": "<the reworded text>"}'
            ."\n\nWhat was refused:\n".mb_substr($text, 0, 1500);
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(45)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 1200,
                    'messages' => [['role' => 'user', 'content' => $ask]]]);
            if (! $r->successful()) return null;
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        } catch (\Throwable $e) {
            Log::warning('refusal advice: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
        $start = strpos($raw, '{');
        $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
        $why = trim((string) ($json['why'] ?? '')); $wording = trim(preg_replace('/\s+/u', ' ', (string) ($json['wording'] ?? '')));
        // A rewording that lost most of the request, or repeats it unchanged, is no advice.
        if ($why === '' || $wording === '' || $wording === preg_replace('/\s+/u', ' ', $text) || mb_strlen($wording) < 0.5 * mb_strlen($text)) return null;

        return ['why' => mb_substr($why, 0, 240), 'wording' => mb_substr($wording, 0, 1200)];
    }
}
