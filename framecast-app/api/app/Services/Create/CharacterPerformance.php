<?php
namespace App\Services\Create;

/** A plan's explicit performance promises; media availability is not creative acceptance. */
class CharacterPerformance
{
    public static function normalize(mixed $raw, array $context): array
    {
        $text = implode("\n", array_column(array_filter($context['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'user'), 'content'));
        $duration = (float) ($context['settings']['duration_seconds'] ?? 15);
        $result = [];
        $previous = $context['previous_plan']['character_performance'] ?? [];
        $previousRequirements = array_column($context['previous_plan']['requirements'] ?? [], null, 'id');
        // Short follow-ups must not erase an earlier performance promise, but a re-planned one for the same
        // words of the brief (same kind and quote, e.g. now through the attached talking face) replaces it.
        $replanned = collect(is_array($raw) ? $raw : [])->filter(fn ($r) => is_array($r))->map(fn ($r) => ($r['kind'] ?? '').'|'.trim((string) ($r['source_quote'] ?? '')))->flip();
        foreach ([...$previous, ...(is_array($raw) ? $raw : [])] as $index => $r) {
            if (! is_array($r)) continue;
            if ($index < count($previous) && $replanned->has(($r['kind'] ?? '').'|'.trim((string) ($r['source_quote'] ?? '')))) continue;
            $quote = trim(is_string($r['source_quote'] ?? null) ? $r['source_quote'] : '');
            $action = mb_substr(trim(is_string($r['action'] ?? null) ? $r['action'] : ''), 0, 240);
            $kind = $r['kind'] ?? '';
            if ($quote === '' || mb_strlen($quote) > 240 || ! str_contains($text, $quote) || $action === '' || ! in_array($kind, ['facial', 'body', 'speech'], true)) continue;
            // A single sentence can request several facial actions; do not collapse them.
            $id = 'perf-'.substr(hash('sha256', $kind.'|'.$quote.'|'.$action), 0, 16);
            $start = is_numeric($r['start'] ?? null) ? (float) $r['start'] : -1;
            $end = is_numeric($r['end'] ?? null) ? (float) $r['end'] : -1;
            $valid = is_finite($start) && is_finite($end) && $start >= 0 && $end > $start && $end <= $duration;
            $contract = $context['_requirement_contract'] ?? [];
            $requirements = array_column($contract['requirements'] ?? [], null, 'id');
            $links = RequirementContract::links($r['requirement_ids'] ?? [], $requirements, $contract['_aliases'] ?? []);
            if (! $links) $links = array_keys(array_filter($requirements, fn ($requirement) => $requirement['text'] === $action && $requirement['source_quote'] === $quote));
            // An explicitly removed linked requirement must not survive as an old performance promise.
            if ($contract && ! empty($r['requirement_ids']) && ! $links) continue;
            // Replaced requirements stay mandatory in the general contract, but an old
            // action/timing cannot masquerade as their newly planned performance.
            if ($index < count($previous) && array_filter($links, fn ($link) => isset($previousRequirements[$link]) && ($requirements[$link]['version'] ?? 1) !== ($previousRequirements[$link]['version'] ?? 1))) continue;
            $result[$id] = ['requirement_ids' => $links, 'id' => $id, 'kind' => $kind, 'action' => mb_substr($action, 0, 240), 'source_quote' => $quote,
                'start' => $valid ? $start : null, 'end' => $valid ? $end : null,
                'route' => in_array($r['route'] ?? '', ['generated_video', 'prepared_rig', 'face_kit', 'poses', 'mascot3d'], true) ? $r['route'] : 'unresolved',
                'tool' => in_array($r['tool'] ?? '', ['animate_image', 'talking_shot', 'talking_take'], true) ? $r['tool'] : null];
        }
        abort_if(count($result) > 24, 422, 'This plan has too many character actions. Split it into shorter videos.');
        return array_values($result);
    }

    /** @param array $files the conversation's attachments ({face_kit?, rig?}): a talking face or a ready rig performs without new media. */
    public static function issues(array $plan, array $settings, array $media, array $files = []): array
    {
        $issues = []; $spans = [];
        $faceKit = collect($files)->contains(fn ($f) => ! empty($f['face_kit']));
        $rigReady = collect($files)->contains(fn ($f) => (bool) data_get($f, 'rig.ready'));
        // Gestures by cutting between attached body-pose images (any attached still other than a face kit's own layers).
        $patchIds = collect($files)->flatMap(fn ($f) => array_column((array) data_get($f, 'face_kit.patches', []), 'asset_id'))->all();
        $poseImages = collect($files)->contains(fn ($f) => ($f['asset_type'] ?? '') === 'image' && empty($f['face_kit']) && ! in_array($f['asset_id'] ?? null, $patchIds, true));
        foreach ($plan['character_performance'] ?? [] as $r) {
            $why = null;
            $tool = $r['tool'] ?? null;
            $item = collect($media)->first(fn ($m) => ($m['kind'] ?? '') === $tool && ($tool !== 'animate_image' || ($m['subject'] ?? '') === 'approved_character'));
            // A drawn character (3D mascot, face kit) buys nothing per action, so an action with no timing simply runs
            // the whole video ("talks throughout", "blinks throughout"); bought clips still need their span.
            $drawn = in_array($r['route'] ?? '', ['mascot3d', 'face_kit'], true);
            if (! $drawn && (($r['start'] ?? null) === null || ($r['end'] ?? null) === null) || ($r['end'] ?? 0) > ($settings['duration_seconds'] ?? 15)) $why = 'Choose the scene timing for this action.';
            // The character's own talking face (cut from an expression sheet) speaks, blinks and reacts; body actions need poses or animation.
            elseif (($r['route'] ?? '') === 'face_kit') {
                if (! $faceKit) $why = 'Attach the character\'s talking face, or choose a generated performance.';
                elseif ($r['kind'] === 'body') $why = 'A talking face covers speech and expressions, not body actions; use the attached poses or an animation.';
                elseif ($r['kind'] === 'speech' && (empty($plan['narration']) || ($settings['audio'] ?? 'original') === 'silent')) $why = 'Speaking on camera needs an approved script and audio enabled.';
                if ($why) $issues[] = ['id' => $r['id'], 'action' => $r['action'], 'message' => $why];
                continue;
            }
            // The plan's 3D mascot speaks and makes expressions itself; it has no arms for gestures.
            elseif (($r['route'] ?? '') === 'mascot3d') {
                if (empty($plan['mascot3d'])) $why = 'This plan has no 3D mascot; design one or choose another route.';
                // Moving the whole figure (slide, pop, turn, bob, hop) needs no arms; hand and arm gestures do.
                elseif ($r['kind'] === 'body' && preg_match('/\b(arms?|hands?|wav(e|es|ing)|point(s|ing)?|clap|thumbs?|grab|hold(s|ing)?|hug|shrug|gestur\w*|fist|salute|walk\w*|run(s|ning)?|jump\w*|dance\w*|legs?)\b/i', $r['action'] ?? '')) $why = 'The 3D mascot turns, nods and bobs but has no arms or legs yet; plan gestures as head and body moves or use poses.';
                elseif ($r['kind'] === 'speech' && (empty($plan['narration']) || ($settings['audio'] ?? 'original') === 'silent')) $why = 'Speaking on camera needs an approved script and audio enabled.';
                if ($why) $issues[] = ['id' => $r['id'], 'action' => $r['action'], 'message' => $why];
                continue;
            }
            elseif (($r['route'] ?? '') === 'poses') {
                if ($r['kind'] !== 'body') $why = 'Cutting between poses covers gestures; speech and expressions need the talking face or a generated performance.';
                elseif (! $poseImages) $why = 'Attach the character\'s body poses, or choose a generated performance.';
                if ($why) $issues[] = ['id' => $r['id'], 'action' => $r['action'], 'message' => $why];
                continue;
            }
            elseif (($r['route'] ?? '') === 'prepared_rig' && $rigReady && $r['kind'] === 'facial') continue;
            elseif (($r['route'] ?? '') === 'prepared_rig') $why = 'This character needs approved layered artwork. A flat image is not rig-ready; choose a generated performance or prepare the rig first.';
            elseif (($r['route'] ?? '') !== 'generated_video' || ! $item) $why = 'Add a character-animation or talking-video task to the plan; still poses cannot perform this action.';
            elseif ($r['kind'] === 'speech' && ! in_array($tool, ['talking_shot', 'talking_take'], true)) $why = 'Speaking on camera needs a talking-video task, not a silent animation.';
            elseif ($r['kind'] === 'speech' && (empty($plan['narration']) || ($settings['audio'] ?? 'original') === 'silent')) $why = 'Speaking on camera needs an approved script and audio enabled.';
            elseif ($r['kind'] === 'body' && $tool !== 'animate_image') $why = 'A talking-head task does not establish walking or hand gestures. Plan the body action explicitly.';
            elseif (! collect($media)->contains('kind', 'character_poses')) $why = 'Add a character master to this plan so the performance uses the approved design.';
            if ($why) $issues[] = ['id' => $r['id'], 'action' => $r['action'], 'message' => $why];
            else $spans[$tool][] = $r;
        }
        foreach ($spans as $tool => $rows) {
            $item = collect($media)->firstWhere('kind', $tool);
            $limit = $tool === 'animate_image' ? 5 : (float) ($item['seconds'] ?? ($tool === 'talking_shot' ? 4 : 15));
            // One catalogue task supplies one performance span; do not silently loop it.
            $span = max(array_column($rows, 'end')) - min(array_column($rows, 'start'));
            if ($span > $limit + .001) $issues[] = ['id' => $rows[0]['id'], 'action' => $rows[0]['action'], 'message' => 'The planned '.$tool.' supplies '.$limit.' seconds, but the requested performance spans '.$span.' seconds. Shorten the span or replan the footage.'];
        }
        return $issues;
    }

    /** What a conversation's attachments can perform by themselves: a talking face (face_kit) or a ready layered rig. */
    public static function performers(string $conversationId): array
    {
        return \App\Models\Asset::whereIn('id', \Illuminate\Support\Facades\DB::table('create_attachments')->where('conversation_id', $conversationId)->pluck('asset_id'))->get()
            ->map(fn ($a) => ['asset_id' => (int) $a->id, 'asset_type' => $a->asset_type, 'face_kit' => data_get($a->metadata_json, 'face_kit'), 'rig' => data_get($a->metadata_json, 'rig')])->all();
    }

    public static function assertReady(array $plan, array $settings, array $media, array $files = []): void
    {
        $issues = self::issues($plan, $settings, $media, $files);
        abort_if($issues !== [], 422, 'Character motion needs a plan update: '.($issues[0]['action'] ?? '').'. '.($issues[0]['message'] ?? ''));
    }
}
