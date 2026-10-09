<?php

namespace App\Services\Create;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Script Writer (the dashboard's card modal, 2026-10-09): a short voiceover script shaped by the kind of video the user
 * picked and sized to its length. Free for the user, with a daily limit per workspace; one draft per call. It only
 * uses what the user typed and their brand's name: anything it would need to invent (a price, an offer, a number) is
 * left as a [bracket] for the user to fill.
 */
class ScriptWriter
{
    public const DAILY_LIMIT = 20;

    /** The shape each kind of video follows: its parts, in order, and how each should sound. */
    public const SHAPES = [
        'offer_ad' => ['name' => 'a short product ad', 'parts' => ['Hook' => 'a question or bold line that stops the scroll', 'Benefit' => 'the one thing the product does for the viewer', 'Offer' => 'the offer and what to do now']],
        'launch_promo' => ['name' => 'a launch teaser', 'parts' => ['Tease' => 'something is coming', 'Reveal' => 'what it is, in one line', 'When' => 'the date, or where to get it']],
        'testimonial' => ['name' => 'a UGC testimonial spoken to camera in the first person, natural and unscripted-sounding', 'parts' => ['Problem' => 'what was wrong before', 'Product' => 'finding the product', 'Result' => 'what changed, and a nudge to try it']],
        'explainer' => ['name' => 'a short explainer', 'parts' => ['Question' => 'the question the viewer has', 'How it works' => 'the idea, steps or numbers, plainly', 'Takeaway' => 'what to remember or do']],
        'listicle' => ['name' => 'a quick tips video', 'parts' => ['Hook' => 'why these tips matter', 'Tip 1' => 'first tip', 'Tip 2' => 'second tip', 'Tip 3' => 'third tip', 'Close' => 'what to do next']],
    ];

    /** @return array{sections: list<array{label: string, text: string}>, words: int} */
    public function write(User $user, string $type, string $about, int $seconds): array
    {
        abort_unless(isset(self::SHAPES[$type]), 422, 'Choose a kind of video first.');
        $about = trim($about);
        abort_if(mb_strlen($about) < 3, 422, 'Describe the product or idea in a line first, for example: "Dewbloom vitamin C serum, for dull skin, 20% off this week".');
        abort_if((string) config('services.anthropic.key') === '', 503, 'Script Writer is not available here.');
        $key = 'create-script-writer:'.$user->workspace_id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($key, self::DAILY_LIMIT), 429, 'That is today\'s '.self::DAILY_LIMIT.' scripts. Write your own, or try again tomorrow.');
        RateLimiter::hit($key, 86400);

        // The planner's measure: about 2 words a second, leaving a breath at the end.
        $seconds = max(5, min(30, $seconds));
        $words = (int) max(8, round(($seconds - 1.5) * 2));
        $shape = self::SHAPES[$type];
        $brand = trim((string) (DB::table('brand_kits')->where('workspace_id', $user->workspace_id)->orderBy('id')->value('name') ?: ''));
        $parts = implode("\n", array_map(fn ($label, $how) => "- {$label}: {$how}", array_keys($shape['parts']), $shape['parts']));
        $ask = "Write the voiceover script for {$shape['name']}, about {$seconds} seconds long: about {$words} words in total, short spoken lines.\n"
            ."Follow these parts, in order, one or two short lines each:\n{$parts}\n"
            ."Use only what the user wrote below".($brand !== '' ? " and the brand name \"{$brand}\"" : '').". Never invent a price, discount, number, date, result, review or claim: where the script needs one the user did not give, write a short placeholder in square brackets, such as [your offer]. Write brand and product names exactly as the user wrote them. No stage directions, no emojis, no hashtags.\n"
            .'Reply with JSON only: {"sections": [{"label": "<part name>", "text": "<the spoken words>"}]}'
            ."\n\nThe user wrote:\n".mb_substr($about, 0, 1500);

        $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(45)
            ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 1200, 'messages' => [['role' => 'user', 'content' => $ask]]]);
        if (! $r->successful()) {
            \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status());
            abort(503, 'Script Writer could not write a script just now. Try again in a moment.');
        }
        $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($raw, '{');
        $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
        $sections = collect((array) ($json['sections'] ?? []))->filter(fn ($s) => is_array($s) && trim((string) ($s['text'] ?? '')) !== '')
            ->map(fn ($s) => ['label' => mb_substr(trim((string) ($s['label'] ?? '')), 0, 30), 'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s['text'])), 0, 300)])
            ->take(8)->values()->all();
        abort_unless($sections, 503, 'Script Writer could not write a script just now. Try again in a moment.');
        $count = array_sum(array_map(fn ($s) => count(preg_split('/\s+/u', $s['text'], -1, PREG_SPLIT_NO_EMPTY)), $sections));

        return ['sections' => $sections, 'words' => $count];
    }
}
