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
        // One self-repair attempt. The first real run failed on a rule the
        // prompt states tersely — a silent cutaway outside demo — and the
        // right response to a near-miss is to hand the director its own
        // error, not to hand the user ours.
        $previousError = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->planOnce($scriptText, $product, $context, $durationSeconds,
                    $language, $availableFootage, $format, $reference, $library, $previousError);
            } catch (ValidationException $e) {
                throw $e; // planOnce only throws this for the terminal failure
            } catch (\Throwable $e) {
                $previousError = mb_substr($e->getMessage(), 0, 300);
                Log::info('UGC director retrying after invalid plan', ['error' => $previousError]);
            }
        }

        Log::warning('UGC director returned no usable plan', ['error' => $previousError]);
        throw ValidationException::withMessages(['plan' => 'The director could not produce a valid plan. No credits were spent. Try again or simplify the brief. Reaction clips need a visual brief, not a spoken script.']);
    }

    private function planOnce(string $scriptText, string $product, string $context, int $durationSeconds,
        string $language, array $availableFootage, string $format, array $reference,
        array $library, string $previousError): array
    {
        try {
            $result = $this->ai->generate('ugc_shot_plan', [
                'script_text' => trim($scriptText), 'product' => $product, 'context' => $context,
                'duration' => (string) $durationSeconds, 'language' => $language,
                'available_footage' => implode(', ', $availableFootage),
                // With a multi-beat reference, reaction is off the menu, not
                // merely discouraged — the whole point of the reference is
                // its structure, and reaction is by definition one shot.
                'format' => $format === 'auto' && count($reference['beats'] ?? []) > 1
                    ? 'auto — choose from direct_camera, demo, story or text_led; reaction is NOT available because the reference has multiple beats'
                    : $format,
                'reference' => $this->referenceBrief($reference),
                'library' => $this->libraryBrief($library),
                'previous_error' => $previousError !== ''
                    ? 'Your previous plan was rejected: '.$previousError.' Return a corrected plan that fixes exactly this.'
                    : '',
            ], 3500, 0.3);
            $parsed = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''));
            $chosen = (string) ($parsed['format'] ?? '');
            if (! in_array($chosen, UgcPlan::FORMATS, true) || ($format !== 'auto' && $format !== $chosen)) {
                throw new \UnexpectedValueException('Director returned a different format.');
            }
            if ($chosen === 'reaction' && count($reference['beats'] ?? []) > 1) {
                throw new \UnexpectedValueException(sprintf(
                    'The reference has %d beats; the single-shot reaction format discards its structure. Choose demo or story and mirror the beats.',
                    count($reference['beats']),
                ));
            }
            $raw = (array) ($parsed['segments'] ?? []);
            // The director sometimes writes a spoken 'reaction' inside a
            // demo/story — a hybrid the validator rightly refuses. A reaction
            // that speaks IS an on-camera beat; coerce rather than fail the
            // whole plan over a label.
            if ($chosen !== 'reaction') {
                foreach ($raw as $i => $seg) {
                    if (is_array($seg) && ($seg['kind'] ?? '') === 'reaction'
                        && trim((string) ($seg['script_text'] ?? '')) !== '') {
                        $raw[$i]['kind'] = 'on_camera';
                    }
                }
            }
            // Same coercion spirit for the silent-cutaway rule the small
            // director keeps tripping: in demo/text_led a silent generated
            // b-roll just needs its burned-in line — the shot's own visual
            // direction stands in (footage planner precedent). In
            // direct_camera/story a silent shot is a mistake by rule, so the
            // shot is cut rather than the whole plan.
            foreach ($raw as $i => $seg) {
                if (! is_array($seg) || ($seg['kind'] ?? '') !== 'b_roll'
                    || trim((string) ($seg['script_text'] ?? '')) !== ''
                    || trim((string) ($seg['headline'] ?? '')) !== ''
                    || (($seg['source'] ?? null) === 'upload' && ! empty($seg['asset_id']))) {
                    continue;
                }
                if (in_array($chosen, ['demo', ...UgcPlan::STILL_ONLY_FORMATS], true)) {
                    $raw[$i]['headline'] = mb_substr(trim((string) ($seg['visual_brief'] ?? '')), 0, 80);
                } elseif (count($raw) > 1) {
                    unset($raw[$i]);
                }
            }
            $raw = array_values($raw);
            $segments = UgcPlan::normalise($raw, $chosen);
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
                'presenter' => mb_substr(trim((string) ($parsed['presenter'] ?? '')), 0, 300),
                'credits_per_character' => UgcPlan::quote($segments),
                'reasoning' => mb_substr((string) ($parsed['reasoning'] ?? ''), 0, 600),
                'warnings' => UgcPlan::warnings($segments, $chosen),
            ];
        } catch (ValidationException $e) {
            // normalise()'s message says exactly what rule broke — that is the
            // repair instruction, so surface it to the retry loop.
            throw new \UnexpectedValueException(implode(' ', array_map(
                fn ($m) => implode(' ', (array) $m), $e->errors(),
            )), 0, $e);
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

            $shots = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''))['shots'] ?? [];

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

    /**
     * Alternative openings for a plan that already works, so a batch can be one
     * idea with six openings rather than one opening on six faces.
     *
     * Character fan-out answers "who says it" and was already there. This
     * answers "how it starts", which is the axis that actually decides whether
     * anyone watches the rest.
     *
     * Each variant is re-anchored: changing the opening changes what the first
     * shot's visual was chosen to answer, and a visual left pointing at a line
     * that is no longer spoken is exactly what anchoring exists to catch.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array{label: string, segments: array<int, array<string, mixed>>}>
     */
    public function hookVariants(array $segments, string $format, int $count, string $product = '', string $context = ''): array
    {
        $first = null;
        foreach ($segments as $i => $seg) {
            if (trim((string) $seg['script_text']) !== '') {
                $first = $i;
                break;
            }
        }
        if ($first === null) {
            throw ValidationException::withMessages([
                'segments' => 'This ad has no spoken opening to vary. Hook variants need a take that starts with words.',
            ]);
        }

        try {
            $result = $this->ai->generate('ugc_hook_variants', [
                'product' => $product ?: 'not said',
                'context' => $context ?: 'not said',
                'script' => UgcPlan::script($segments),
                'opening' => (string) $segments[$first]['script_text'],
                'count' => (string) $count,
            ], 1600, 0.8, ['operation' => 'ugc_hook_variants']);

            $raw = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''))['variants'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('UGC hook variants produced nothing usable', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            throw ValidationException::withMessages([
                'variants' => 'Could not write alternative openings just now. No credits were spent — try again, or generate the take as it stands.',
            ]);
        }

        $out = [];
        foreach (array_slice((array) $raw, 0, $count) as $v) {
            $text = trim((string) ($v['script_text'] ?? ''));
            if (! is_array($v) || $text === '') {
                continue;
            }
            $swapped = $segments;
            $swapped[$first]['script_text'] = $text;
            $swapped[$first]['anchor'] = $text;
            if (array_key_exists('headline', $v) && is_string($v['headline'])) {
                $swapped[$first]['headline'] = trim($v['headline']);
            }

            // normalise() re-derives the duration from the new word count, and
            // reanchor then reports any shot the swap stranded.
            $swapped = UgcPlan::normalise($swapped, $format);
            $out[] = [
                'label' => mb_substr(trim((string) ($v['label'] ?? 'Alternative')), 0, 40) ?: 'Alternative',
                'segments' => UgcPlan::reanchor($swapped, UgcPlan::script($swapped)),
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'variants' => 'No usable openings came back. Nothing was changed.',
            ]);
        }

        return $out;
    }
}
