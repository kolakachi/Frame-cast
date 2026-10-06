<?php
namespace App\Services\Create\Planning;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Before a creation is planned, one question at a time: only what would change the plan and cannot be chosen well
 * without the user (the product, the offer, who it is for, which footage). Looks, colours, music, voice and copy are
 * not asked; the user changes those on the plan. Each answer is a message, so the next call sees it.
 */
class Clarifier
{
    public const MODEL = 'claude-sonnet-5';
    public const MAX_QUESTIONS = 3;

    /** What a free-form answer to "how closely should I follow the reference?" means: exact, similar, inspired or null. */
    public function referenceMatch(string $answer): ?string
    {
        if (config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '') return null;
        $prompt = "A user was asked how closely a video should follow their reference video: \"exact\" (copy it moment for moment), \"similar\" (keep its format, look and pacing with their own story) or \"inspired\" (take just the idea). "
            .'Their answer: "'.mb_substr($answer, 0, 600).'". Reply with JSON only: {"match": "exact" | "similar" | "inspired" | null}';
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(20)
                ->post('https://api.anthropic.com/v1/messages', ['model' => 'claude-haiku-4-5-20251001', 'max_tokens' => 60, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $start = strpos($raw, '{');
            $m = $start === false ? null : (json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true)['match'] ?? null);
            return in_array($m, ['exact', 'similar', 'inspired'], true) ? $m : null;
        } catch (\Throwable) { return null; }
    }

    /** The next question, or null when the plan can be made now. */
    public function question(array $context, int $askedSoFar): ?string
    {
        if (config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '' || $askedSoFar >= self::MAX_QUESTIONS) return null;
        $messages = collect($context['messages'] ?? [])->map(fn ($m) => strtoupper((string) $m['role']).': '.mb_substr((string) $m['content'], 0, 2000))->implode("\n");
        $files = collect($context['files'] ?? [])->map(fn ($f) => '- "'.$f['title'].'" ('.$f['asset_type'].', '.($f['purpose'] === 'source' ? 'goes in the video' : 'reference to follow').')'
            .(data_get($f, 'reference.summary') ? ': '.mb_substr((string) data_get($f, 'reference.summary'), 0, 300) : ''))->implode("\n");
        $settings = collect($context['settings'] ?? [])->only(['output_kind', 'duration_seconds', 'aspect_ratio', 'reference_match', 'approved_facts'])->toJson();
        $prompt = "You are about to plan a short video for this user. Decide whether you must ask them ONE question first.\n"
            ."Ask only when the answer would change the plan and you cannot choose it well yourself: e.g. which product or offer to feature, the price or call to action, who it is for, which of their files to use. "
            ."Never ask about style, colours, music, voice, pacing or exact wording: you choose those and the user edits them on the plan. "
            ."Never ask something the conversation already answers, and never ask more than you need: ".self::MAX_QUESTIONS." questions in total at most, ".$askedSoFar." asked so far. "
            ."If the user said to go ahead, or you can make a good plan now, ask nothing.\n"
            .'Reply with JSON only: {"question": "one short question, under 25 words, plain words" | null}'
            ."\n\nSettings: ".$settings."\nFiles:\n".($files ?: '(none)')."\n\nConversation:\n".$messages;
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 300, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            if (! $r->successful()) { Log::warning('Create clarifier: model call failed', ['status' => $r->status()]); return null; }
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $start = strpos($raw, '{');
            $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
            $q = trim((string) ($json['question'] ?? ''));
            return $q === '' ? null : mb_substr($q, 0, 240);
        } catch (\Throwable $e) {
            Log::warning('Create clarifier: '.mb_substr($e->getMessage(), 0, 200));
            return null;
        }
    }
}
