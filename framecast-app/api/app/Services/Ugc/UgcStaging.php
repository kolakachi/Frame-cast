<?php

namespace App\Services\Ugc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The ready check (2026-10-12, from Jellyfish's and Toonflow's shot checks): before any clip is paid for, a cheap model
 * reads every shot of a take in order and writes how each is physically staged, so a video model gets concrete hands
 * instead of a general "respect physics": what each hand holds, whether the phone is handheld or propped, the product's
 * state at the start (carried from the shot before) and the one action. Take 283 held the phone, the pouch and the
 * peanuts at once, and re-opened the pouch in every clip. A check that cannot run leaves the take as compiled.
 */
class UgcStaging
{
    private const PROMPT = <<<'TXT'
You check the physical staging of a short creator video before it is generated shot by shot by a video model. Each shot is filmed separately, so the model only knows what its own shot says.

For every shot, in order, decide:
- what the left hand and the right hand hold or do. A person has two hands. When the phone is handheld (a selfie), one hand holds the phone and only the other is free.
- whether the phone is handheld or propped on a surface. It must be propped whenever the action needs both hands (opening, tearing, pouring into a hand, unscrewing, applying, preparing).
- the product's state at the start of the shot, carried from the end of the shot before (a pouch opened in shot 1 is still open in shot 2; peanuts eaten are gone), and at its end.
- the one main action. Never repeat an action an earlier shot already did; continue from where it ended.
- an action happens in its own shot only, and that shot shows it being done. The shot before it never starts it: no raising food toward the mouth before the bite shot, no hand on the seal before the opening shot; there the staging says so plainly ("She does not bite in this shot.").

Write each shot's staging as one or two plain sentences a video model can follow, for example: "Phone propped on the desk. Left hand holds the open orange pouch, right hand tips a few peanuts into the left palm. The pouch starts open and stays open."
Do not change the spoken words, the place or the product. Answer JSON only: {"shots":[{"staging":"..."}]} with exactly one entry per shot, in the same order.
TXT;

    /**
     * One staging sentence per chunk, in order, or [] when the check could not run or answered something else.
     *
     * @param  list<array<string, mixed>>  $chunks  UgcOneShotCompiler output
     * @return list<string>
     */
    public function stage(array $chunks, string $product = ''): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '' || $chunks === []) return [];
        $shots = [];
        foreach (array_values($chunks) as $i => $c) {
            // The scene and its words only: the shared preamble and closing rules are the same in every shot.
            $parts = preg_split("/\n\n/", (string) ($c['prompt'] ?? ''));
            $shots[] = 'Shot '.($i + 1).': '.mb_substr(trim((string) ($parts[1] ?? $parts[0] ?? '')), 0, 1500);
        }
        $content = self::PROMPT."\n\nProduct: ".($product !== '' ? $product : 'the product in the shots')."\n\n".implode("\n\n", $shots);
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(45)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) {
                Log::warning('ugc staging: the check failed', ['status' => $r->status()]);
                return [];
            }
            $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        } catch (\Throwable $e) {
            Log::warning('ugc staging: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return [];
        }

        return self::parse($text, count($chunks));
    }

    /** The staging lines from the model's answer; [] unless there is exactly one per shot. */
    public static function parse(string $text, int $count): array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        $shots = is_array($json['shots'] ?? null) ? $json['shots'] : [];
        $lines = array_map(fn ($s) => is_array($s) ? mb_substr(trim((string) ($s['staging'] ?? '')), 0, 400) : '', $shots);
        if (count($lines) !== $count || in_array('', $lines, true)) return [];

        return array_values($lines);
    }

    /** Each chunk's prompt with its staging put right after its scene, before the closing rules. */
    public static function apply(array $chunks, array $staging): array
    {
        if (count($staging) !== count($chunks)) return $chunks;
        foreach (array_values($chunks) as $i => $c) {
            $parts = explode("\n\n", (string) ($c['prompt'] ?? ''));
            $at = count($parts) >= 3 ? count($parts) - 1 : count($parts);
            array_splice($parts, $at, 0, ['Physical staging, follow exactly: '.$staging[$i]]);
            $chunks[$i]['prompt'] = implode("\n\n", $parts);
        }

        return $chunks;
    }
}
