<?php
namespace App\Services\Create\Planning;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Before a creation is planned, one question at a time: only what would change the plan and cannot be chosen well
 * without the user (the product, the offer, who it is for, which footage), and material only the user has (their
 * screens, real results, numbers) when the reference depends on it. Looks, colours, music, voice and copy are not
 * asked; the user changes those on the plan. Each answer is a message, so the next call sees it.
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
            \App\Services\Create\PlanningCosts::call('questions', 'claude-haiku-4-5-20251001', (array) $r->json('usage', []));
            $m = $start === false ? null : (json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true)['match'] ?? null);
            return in_array($m, ['exact', 'similar', 'inspired'], true) ? $m : null;
        } catch (\Throwable) { return null; }
    }

    /**
     * For a change to an existing plan or video: one question when the request is too vague to act on ("make it
     * better", "different vibe", "fix it"), naming 2 to 4 likely meanings; null when it is clear enough.
     */
    public function changeQuestion(array $context): ?string
    {
        if (config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '') return null;
        $messages = collect($context['messages'] ?? [])->slice(-6)->map(fn ($m) => strtoupper((string) $m['role']).': '.mb_substr((string) $m['content'], 0, 1200))->implode("\n");
        $prompt = "A user is changing a short video they already have a plan or a version of. Their latest message is the change they want.\n"
            ."Decide whether it is clear enough to act on. It is clear when it names what to change or how (\"bigger title\", \"warmer colours\", \"cut the second scene\", \"slower voice\"). "
            ."It is too vague when it could mean very different changes (\"make it better\", \"different vibe\", \"fix it\", \"I don't like it\"). Only then ask ONE short question naming 2 to 4 likely meanings, e.g. \"Which part should change: the hook, the colours, the voice, or the pacing?\"\n"
            .'Reply with JSON only: {"question": "under 30 words" | null}'."\n\nConversation (latest last):\n".$messages;
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 200, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            if (! $r->successful()) { \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status()); return null; }
            \App\Services\Create\PlanningCosts::call('questions', self::MODEL, (array) $r->json('usage', []));
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $start = strpos($raw, '{');
            $q = trim((string) (($start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true))['question'] ?? ''));
            return $q === '' ? null : mb_substr($q, 0, 240);
        } catch (\Throwable) { return null; }
    }

    /** The next question, or null when the plan can be made now. */
    public function question(array $context, int $askedSoFar): ?string
    {
        if (config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '' || $askedSoFar >= self::MAX_QUESTIONS) return null;
        $messages = collect($context['messages'] ?? [])->map(fn ($m) => strtoupper((string) $m['role']).': '.mb_substr((string) $m['content'], 0, 2000))->implode("\n");
        $files = collect($context['files'] ?? [])->map(fn ($f) => '- "'.$f['title'].'" ('.$f['asset_type'].', '.($f['purpose'] === 'source' ? 'goes in the video' : 'reference to follow').')'
            .(data_get($f, 'reference.summary') ? ': '.mb_substr((string) data_get($f, 'reference.summary'), 0, 300) : ''))->implode("\n");
        // What the reference shows moment by moment, so material only the user has (their screens, real results,
        // numbers) is asked for before the plan, not left to a fallback after it.
        $moments = collect($context['files'] ?? [])->flatMap(fn ($f) => (array) data_get($f, 'reference.study.moments', []))
            ->map(fn ($m) => '- '.($m['kind'] ?? 'moment').': '.mb_substr((string) ($m['visual'] ?? $m['purpose'] ?? ''), 0, 110))->take(30)->implode("\n");
        // A document's facts already answer what it states (the offer, price, audience), so they are never asked again.
        $docs = collect($context['documents'] ?? [])->map(fn ($d) => '- "'.$d['title'].'": '.mb_substr((string) ($d['summary'] ?? ''), 0, 300)
            .(! empty($d['facts']) ? ' Facts: '.mb_substr(implode('; ', $d['facts']), 0, 900) : ''))->implode("\n");
        $brand = collect($context['brand_library'] ?? [])->map(fn ($b) => ($b['role'] ?? 'item').': '.($b['title'] ?? ''))->implode(', ');
        $settings = collect($context['settings'] ?? [])->only(['output_kind', 'duration_seconds', 'aspect_ratio', 'reference_match', 'approved_facts'])->toJson();
        $prompt = "You are about to plan a short video for this user. Decide whether you must ask them ONE question first.\n"
            ."Ask only when the answer would change the plan and you cannot choose it well yourself: e.g. which product or offer to feature, the price or call to action, who it is for, which of their files to use. "
            ."Never ask about style, colours, music, voice, pacing or exact wording: you choose those and the user edits them on the plan. "
            ."Never ask something the conversation already answers, and never ask more than you need: ".self::MAX_QUESTIONS." questions in total at most, ".$askedSoFar." asked so far. "
            ."Also ask for material only the user has when the reference or brief depends on it and nothing in Files, the brand library or approved facts covers it: real screens or recordings of their product, real results or videos made with it, numbers or reviews they can stand behind, product photos, their logo. "
            ."Ask for all of it in one question that names each item and says they can attach it here or reply \"go without\"; ask for material once only. "
            ."If the user said to go ahead, or you can make a good plan now, ask nothing.\n"
            .'Reply with JSON only: {"question": "one short question, under 25 words (up to 45 when asking for material), plain words" | null}'
            ."\n\nSettings: ".$settings."\nFiles:\n".($files ?: '(none)').($docs ? "\nDocuments the user added:\n".$docs : '')."\nBrand library: ".($brand ?: '(empty)')
            .($moments ? "\nWhat the reference shows:\n".$moments : '')."\n\nConversation:\n".$messages;
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 300, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            if (! $r->successful()) { Log::warning('Create clarifier: model call failed', ['status' => $r->status()]); \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status()); return null; }
            \App\Services\Create\PlanningCosts::call('questions', self::MODEL, (array) $r->json('usage', []));
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $start = strpos($raw, '{');
            $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
            $q = trim((string) ($json['question'] ?? ''));
            return $q === '' ? null : mb_substr($q, 0, 400);
        } catch (\Throwable $e) {
            Log::warning('Create clarifier: '.mb_substr($e->getMessage(), 0, 200));
            return null;
        }
    }
}
