<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A swapped voice keeps the version's timing, so each new line has the time its old line had (SWAP-SAFE, 2026-10-19:
 * "54 Maluniu Ave" took 2.93 s where "Open 7am to 10pm" had 2.09 s, and the swap fell into a full build). Before the
 * voice is bought, a line that would take clearly longer to say than the line it replaces gets a shorter spoken version;
 * the words on screen stay as they are (the screen shows "beelitecandles.com", the voice says "Shop Beelite"). A line the
 * worker found too long on an earlier try is shortened by what it measured.
 */
class SpokenFit
{
    /** How much longer a new line may take to say: the worker speeds a line up to 1.15x, less a margin. */
    public const ROOM = 1.1;

    /** Spoken syllables, roughly: numbers, symbols and web addresses as they are said. */
    public static function syllables(string $text): int
    {
        $t = mb_strtolower($text);
        $t = preg_replace_callback('/\b[a-z0-9-]+(\.[a-z]{2,})+\b/u', fn ($m) => str_replace('.', ' dot ', $m[0]), $t);
        // Addresses as they are said ("Ave" is "avenue").
        $t = preg_replace(['/\bave\b\.?/u', '/\bst\b\.?/u', '/\brd\b\.?/u', '/\bblvd\b\.?/u', '/\bste\b\.?/u', '/\bapt\b\.?/u'], ['avenue', 'street', 'road', 'boulevard', 'suite', 'apartment'], $t);
        $t = strtr($t, ['%' => ' percent ', '&' => ' and ', '@' => ' at ', '+' => ' plus ', '$' => ' dollars ', '£' => ' pounds ', '€' => ' euros ', '₹' => ' rupees ', '#' => ' number ', '/' => ' slash ']);
        $count = 0;
        foreach (preg_split('/[^\p{L}\p{N}\']+/u', $t, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (preg_match('/^\d+$/', $word)) { $count += self::numberSyllables($word); continue; }
            if (preg_match('/\d/', $word)) { // "7am", "rm30": letters and digits apart
                foreach (preg_split('/(?<=\d)(?=\D)|(?<=\D)(?=\d)/', $word) as $part) $count += preg_match('/^\d+$/', $part) ? self::numberSyllables($part) : max(1, mb_strlen($part) <= 2 ? mb_strlen($part) : self::wordSyllables($part));
                continue;
            }
            $count += self::wordSyllables($word);
        }
        return max(1, $count);
    }

    private static function wordSyllables(string $w): int
    {
        if (! preg_match('/[aeiouy]/u', $w)) return max(1, mb_strlen($w)); // "kl", "rm": said letter by letter
        $n = preg_match_all('/[aeiouy]+/u', $w);
        if (mb_strlen($w) > 2 && str_ends_with($w, 'e') && ! str_ends_with($w, 'le') && $n > 1) $n--;
        return max(1, $n);
    }

    private static function numberSyllables(string $digits): int
    {
        $n = strlen(ltrim($digits, '0')) ?: 1;
        // "4" one, "24" three, "100" three, "2700" five, a phone number about one and a half a digit.
        return $n === 1 ? 1 : ($n === 2 ? 3 : ($n <= 4 ? 2 * $n - 1 : (int) ceil($n * 1.5)));
    }

    /**
     * The narration to voice for this swap: lines that would not fit shortened, the rest as written. Same count as
     * $new. $measured: line index => how many times too long the worker found it on an earlier try.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @param array<int, float> $measured
     * @return list<string>
     */
    public static function fit(array $old, array $new, array $measured = []): array
    {
        if (! $old || ! $new) return $new;
        $targets = [];
        if (count($old) === count($new)) {
            foreach ($new as $i => $line) {
                $room = (int) floor(self::syllables($old[$i]) * self::ROOM);
                if (isset($measured[$i])) $room = min($room, (int) floor(self::syllables($line) / ($measured[$i] * 1.05)));
                if (self::syllables($line) > $room) $targets[$i] = max(2, $room);
            }
        } else {
            // Re-split lines are voiced as one read: the whole read must fit the whole old one.
            $oldTotal = array_sum(array_map([self::class, 'syllables'], $old)); $newTotal = array_sum(array_map([self::class, 'syllables'], $new));
            $scale = min($oldTotal * self::ROOM / $newTotal, isset($measured[-1]) ? 1 / ($measured[-1] * 1.05) : 1);
            if ($scale < 1) foreach ($new as $i => $line) $targets[$i] = max(2, (int) floor(self::syllables($line) * $scale));
        }
        if (! $targets) return $new;
        $short = self::shorten($new, $targets);
        foreach ($targets as $i => $max) if (isset($short[$i]) && self::syllables($short[$i]) <= $max + 1) $new[$i] = $short[$i];
        return array_values($new);
    }

    /**
     * What an earlier try of this plan's voice swap measured: line index (0-based; -1 for one continuous read) => how
     * many times too long. Read from the worker's message on the failed run.
     *
     * @return array<int, float>
     */
    public static function measured(string $conversationId): array
    {
        return self::measuredFrom(DB::table('composition_runs')->where('conversation_id', $conversationId)->where('status', 'failed')->whereNotNull('input_json->voice_swap')
            ->orderByDesc('created_at')->limit(5)->pluck('stage')->map(fn ($s) => (string) $s)->all());
    }

    /** @param list<string> $stages newest first @return array<int, float> */
    public static function measuredFrom(array $stages): array
    {
        $out = [];
        foreach ($stages as $stage) {
            if (preg_match('/line (\d+) (?:is|takes) ([\d.]+) s (?:to say )?against an? ([\d.]+) s slot/', $stage, $m) && (float) $m[3] > 0) $out[(int) $m[1] - 1] ??= (float) $m[2] / (float) $m[3];
            if (preg_match('/new voice (?:is|takes) ([\d.]+) s (?:to say )?against ([\d.]+) s/', $stage, $m) && (float) $m[2] > 0) $out[-1] ??= (float) $m[1] / (float) $m[2];
        }
        return $out;
    }

    /** The old narration of the version this swap replaces, line by line (its run's plan). */
    public static function baseNarration(?object $base): array
    {
        if (! $base) return [];
        $run = ConversationService::revisionRunId($base->id);
        $input = $run ? json_decode((string) DB::table('composition_runs')->where('id', $run)->value('input_json'), true) : null;
        return array_values(array_map('strval', (array) ($input['plan']['narration'] ?? [])));
    }

    /**
     * Shorter spoken versions of the lines named in $targets (index => most syllables), from the small model; cached.
     *
     * @return array<int, string>
     */
    private static function shorten(array $lines, array $targets): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') return [];
        $ask = "These are voiceover lines of a short video ad. Each listed line is too long to say in the time it has. Rewrite only "
            ."the listed lines so each takes at most the given number of syllables when spoken aloud. Keep the meaning and the brand "
            ."or product name; a web address is said as the brand name instead (\"Shop Beelite\", not \"beelitecandles dot com\"); "
            ."numbers count as they are said; drop filler words first. Keep the same language. The words shown on screen do not "
            ."change, only what is said.\n"
            .'Reply with JSON only: {"lines": {"<index>": "<shorter line>"}}'."\n\nAll lines (index: text):\n"
            .implode("\n", array_map(fn ($l, $i) => "$i: ".$l, $lines, array_keys($lines)))."\n\nShorten (index: at most syllables):\n"
            .implode("\n", array_map(fn ($max, $i) => "$i: $max", $targets, array_keys($targets)));
        return Cache::remember('spoken-fit:'.sha1($ask), now()->addDays(30), function () use ($key, $ask) {
            try {
                $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(30)
                    ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 600,
                        'messages' => [['role' => 'user', 'content' => $ask]]]);
                if (! $r->successful()) return [];
                $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            } catch (\Throwable $e) {
                Log::warning('spoken fit: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
                return [];
            }
            $start = strpos($raw, '{');
            $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
            $out = [];
            foreach ((array) ($json['lines'] ?? []) as $i => $text) if (is_numeric($i) && is_string($text) && trim($text) !== '') $out[(int) $i] = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 300);
            return $out;
        });
    }
}
