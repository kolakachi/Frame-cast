<?php

namespace App\Services\Ugc;

use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Creative decisions are reviewed before spending; invalid plans never silently change format. */
class UgcShotPlanner
{
    public function __construct(private readonly AIGenerationAdapter $ai) {}

    public function plan(string $scriptText, string $product = '', string $context = '', int $durationSeconds = 30,
        string $language = 'en', array $availableFootage = [], string $format = 'auto', array $reference = [],
        array $library = []): array
    {
        try {
            $result = $this->ai->generate('ugc_shot_plan', [
                'script_text' => trim($scriptText), 'product' => $product, 'context' => $context,
                'duration' => (string) $durationSeconds, 'language' => $language,
                'available_footage' => implode(', ', $availableFootage), 'format' => $format,
                'reference' => $this->referenceBrief($reference),
                'library' => $this->libraryBrief($library),
            ], 3500, 0.3);
            $content = trim((string) ($result['content'] ?? $result['text'] ?? ''));
            $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
            $parsed = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            $chosen = (string) ($parsed['format'] ?? '');
            if (! in_array($chosen, UgcPlan::FORMATS, true) || ($format !== 'auto' && $format !== $chosen)) {
                throw new \UnexpectedValueException('Director returned a different format.');
            }
            $segments = UgcPlan::normalise($parsed['segments'] ?? [], $chosen);
            // A model that names a clip we never offered would show the user
            // footage assigned to a shot that cannot be generated — and an id
            // from another workspace would be worse than that. Only ids from
            // the list we handed it survive.
            $segments = $this->keepOfferedAssetsOnly($segments, $library);
            $spoken = UgcPlan::script($segments);
            if (trim($scriptText) !== '' && ! UgcPlan::sameScript($scriptText, $spoken)) {
                throw new \UnexpectedValueException('Director changed the supplied spoken script.');
            }

            return [
                'format' => $chosen, 'script' => $spoken, 'segments' => $segments,
                'credits_per_character' => UgcPlan::quote($segments),
                'reasoning' => mb_substr((string) ($parsed['reasoning'] ?? ''), 0, 600),
                'warnings' => UgcPlan::warnings($segments, $chosen),
            ];
        } catch (\Throwable $e) {
            Log::warning('UGC director returned no usable plan', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            throw ValidationException::withMessages(['plan' => 'The director could not produce a valid plan. No credits were spent. Try again or simplify the brief. Reaction clips need a visual brief, not a spoken script.']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<int, array<string, mixed>>  $library
     * @return array<int, array<string, mixed>>
     */
    private function keepOfferedAssetsOnly(array $segments, array $library): array
    {
        $offered = array_map(fn ($a) => (int) ($a['id'] ?? 0), $library);

        foreach ($segments as $i => $seg) {
            $id = (int) ($seg['asset_id'] ?? 0);
            if ($id > 0 && ! in_array($id, $offered, true)) {
                $segments[$i]['asset_id'] = null;
                // Left as an upload shot with nothing chosen: UgcPlan::warnings
                // already tells the user that shot needs footage selecting, and
                // generate() refuses until it has some.
            }
        }

        return $segments;
    }

    /**
     * Their own clips, offered to the director by id so a shot can be assigned
     * one rather than described and hand-matched afterwards.
     *
     * This is the half of reference-reading that makes it useful: the shape
     * comes from the ad they admire, the substance from footage they already
     * own. Their real product beats anything we would generate of it, and
     * nothing is generated at all.
     *
     * @param  array<int, array<string, mixed>>  $library
     */
    private function libraryBrief(array $library): string
    {
        if ($library === []) {
            return 'none uploaded — plan cutaways as stock or generate';
        }

        $lines = ['Their own uploaded footage. Prefer these over stock or generated stills wherever',
                  'one genuinely answers the shot: it is their real product, and it costs nothing to use.',
                  'Set source to "upload" and asset_id to the id below. Never invent an id, and never',
                  'claim a clip shows something this list does not say it shows.'];
        foreach ($library as $a) {
            $lines[] = sprintf(
                '- id %d (%s%s): %s%s',
                (int) ($a['id'] ?? 0),
                (string) ($a['kind'] ?? 'clip'),
                isset($a['seconds']) && $a['seconds'] ? ', '.$a['seconds'].'s' : '',
                (string) ($a['title'] ?? 'Untitled'),
                ! empty($a['description']) ? ' — '.$a['description'] : '',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The reference's argument, as instructions — never its words or its
     * pictures. Only what each beat was for and roughly how long it ran, so
     * the new ad can make the same case about a different product.
     *
     * @param  array<string, mixed>  $reference
     */
    private function referenceBrief(array $reference): string
    {
        $beats = array_values(array_filter((array) ($reference['beats'] ?? []), 'is_array'));
        if ($beats === []) {
            return 'none — plan from the brief';
        }

        $lines = ['Build the ad in this shape, read off an existing one. Use its STRUCTURE only:',
                  'none of its wording, footage, claims or branding may appear in your plan.'];
        if (! empty($reference['shape'])) {
            $lines[] = 'How that ad is built: '.$reference['shape'];
        }
        foreach ($beats as $i => $b) {
            $secs = max(0.0, round((float) ($b['end'] ?? 0) - (float) ($b['start'] ?? 0), 1));
            $lines[] = sprintf(
                '%d. %s (~%ss) — %s%s',
                $i + 1,
                (string) ($b['role'] ?? 'beat'),
                $secs > 0 ? $secs : '?',
                (string) ($b['does'] ?? ''),
                ! empty($b['on_screen']) ? ' | on screen: '.$b['on_screen'] : '',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Rewrite the visual direction of shots the script has moved out from under,
     * keeping the role each one was playing.
     *
     * Only visual_brief, motion_prompt, headline and the anchor itself change.
     * The spoken words, the kind, the duration and the footage source are the
     * user's, and a repair that edited them would be a rewrite wearing a
     * repair's clothes.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array<string, mixed>>
     */
    public function reanchor(array $segments, string $format): array
    {
        $segments = UgcPlan::reanchor($segments, UgcPlan::script($segments));
        $stale = array_keys(array_filter($segments, fn ($s) => ! empty($s['stale'])));
        if ($stale === []) {
            return $segments;
        }

        $payload = array_map(fn (int $i) => [
            'index' => $i,
            'kind' => $segments[$i]['kind'],
            'role' => $segments[$i]['anchor_role'],
            'lost_anchor' => $segments[$i]['anchor'],
            'spoken_here' => $segments[$i]['script_text'],
            'current_visual_brief' => $segments[$i]['visual_brief'],
        ], $stale);

        try {
            $result = $this->ai->generate('ugc_shot_reanchor', [
                'format' => $format,
                'script' => UgcPlan::script($segments),
                'shots_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ], 2000, 0.3, ['operation' => 'ugc_shot_reanchor']);

            $content = trim((string) ($result['content'] ?? $result['text'] ?? ''));
            $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
            $shots = json_decode($content, true, 16, JSON_THROW_ON_ERROR)['shots'] ?? [];

            foreach ($shots as $shot) {
                $i = (int) ($shot['index'] ?? -1);
                // Only the shots we asked about, and only the fields we allow.
                if (! in_array($i, $stale, true)) {
                    continue;
                }
                foreach (['anchor', 'visual_brief', 'motion_prompt', 'headline'] as $field) {
                    if (array_key_exists($field, $shot) && is_string($shot[$field])) {
                        $segments[$i][$field] = trim($shot[$field]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('UGC re-anchor produced nothing usable', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            throw ValidationException::withMessages([
                'segments' => 'Could not re-direct those shots just now. No credits were spent and nothing was changed — try again, or edit the visual direction yourself.',
            ]);
        }

        // Back through the same gate as any other plan, then re-checked: a
        // repair that left a shot stale has not repaired it.
        $segments = UgcPlan::normalise($segments, $format);

        return UgcPlan::reanchor($segments, UgcPlan::script($segments));
    }
}
