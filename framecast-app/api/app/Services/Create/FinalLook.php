<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Http, Log};

/**
 * Looks at the finished video (todo D1, D2, D4 and the agreement's required items): frames sampled from the final
 * encode, with the approved cast images beside them, read by a cheap vision model. It reports what it could see,
 * with times, and says when it could not see; it never turns an unreadable answer into a pass.
 */
class FinalLook
{
    /** @param array<int, array{time: float, jpeg: string, label?: string}> $frames */
    public function check(string $runId, string $lease, array $frames): array
    {
        $run = app(RunService::class)->currentRun($runId, $lease);
        $input = json_decode((string) $run->input_json, true) ?: [];
        $plan = $input['plan'] ?? [];
        $required = array_values((array) ($plan['agreement']['required'] ?? []));
        $shots = collect($input['plan_media'] ?? [])->where('kind', 'generated_shot')->values()
            ->map(fn ($m, $k) => ['shot' => $k + 1, 'beat' => $m['beat'] ?? null, 'action' => $m['action'] ?? null, 'gaze' => $m['gaze'] ?? null])->filter(fn ($s) => $s['action'])->values()->all();
        $cast = [];
        if (collect($input['plan_media'] ?? [])->contains('kind', 'reference_sheet') && ($c = CharacterApproval::candidate($plan, $input['settings'] ?? [], (int) $run->workspace_id))) {
            foreach ($c['files'] as $k => $f) {
                if (str_starts_with((string) ($c['names'][$k] ?? ''), 'Panel ') || count($cast) >= 3) continue;
                $bytes = app(\App\Services\Create\CreateStorage::class)->get($f['storage_path'] ?? '');
                if (is_string($bytes) && $bytes !== '') $cast[] = ['name' => (string) ($c['names'][$k] ?? 'cast'), 'data' => base64_encode($bytes), 'mime' => (string) ($f['mime_type'] ?? 'image/png')];
            }
        }
        // Without a cast sheet, the user's own photo is the identity a take or shot must keep.
        if (! $cast && collect($input['plan_media'] ?? [])->contains(fn ($m) => in_array('avatar', (array) ($m['refs'] ?? []), true) || ($m['presenter'] ?? '') === 'avatar' || ($m['first_frame'] ?? '') === 'avatar')) {
            foreach (collect($input['input_files'] ?? [])->where('purpose', 'source')->where('asset_type', 'image')->filter(fn ($f) => empty($f['operation']))->take(2) as $f) {
                $bytes = app(\App\Services\Create\CreateStorage::class)->get($f['storage_path'] ?? '');
                if (is_string($bytes) && $bytes !== '' && strlen($bytes) < 4_000_000) $cast[] = ['name' => 'the user (their own photo)', 'data' => base64_encode($bytes), 'mime' => (string) ($f['mime_type'] ?? 'image/jpeg')];
            }
        }
        // A teaching video's opening question, which its final image should answer.
        $hook = collect($plan['scenes'] ?? [])->first(fn ($s) => ($s['arc'] ?? null) === 'hook');
        $question = ($plan['creative_intent']['format'] ?? '') === 'educational' && $hook ? trim((string) (collect($hook['reads'] ?? [])->first(fn ($r) => str_contains((string) $r, '?')) ?? ($hook['reads'][0] ?? ''))) : '';
        $key = (string) config('services.anthropic.key');
        if ($key === '' || ! $frames) return ['status' => 'unverified'];
        $content = [['type' => 'text', 'text' => 'You check a finished short video before it is delivered. Below are frames from it, each with its time, then the approved cast images (identity references), if any. Answer only from what is visible; when you cannot tell, say "unclear". Reply with JSON only:
{"required": [{"id": "<the item id, as given>", "item": "<as given>", "status": "present"|"missing"|"unclear", "time": <seconds or null>, "note": "<under 15 words>"}],
 "identity": {"status": "consistent"|"drift"|"unclear"|"none", "times": [<seconds where a person looks like someone else>], "note": "<under 20 words>"},
 "lettering": {"status": "clean"|"garbled", "times": [<seconds>], "note": "<garbled letters or fake logos baked into the picture; clean overlay captions and real product labels are fine>"},
 "actions": [{"shot": <n>, "status": "present"|"missing"|"unclear", "time": <seconds or null>, "note": "<under 15 words; say if the person looks into the camera when they should not>"}],
 "answer": {"status": "answered"|"not_answered"|"unclear"|"none", "note": "<under 20 words>"}}
Required items, each with its id (each must be visible; spoken-only items are "unclear" here, they are checked by listening; answer every id): '.json_encode(array_map(fn ($t, $k) => ['id' => 'r'.($k + 1), 'item' => $t], $required, array_keys($required)), JSON_UNESCAPED_UNICODE).'
Directed shots (each action should be visible, with its gaze; judge each shot on the frames marked inside it, which follow its action from early to late): '.json_encode($shots, JSON_UNESCAPED_UNICODE)
            .($question !== '' ? "\nThe opening question, which the last frames should visibly answer: ".json_encode($question, JSON_UNESCAPED_UNICODE) : "\nThere is no opening question to check: answer.status is \"none\".")]];
        foreach ($frames as $f) {
            $content[] = ['type' => 'text', 'text' => 'Frame at '.round((float) $f['time'], 1).' s'.(($f['label'] ?? '') !== '' ? ' (inside '.$f['label'].')' : '')];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($f['jpeg'])]];
        }
        foreach ($cast as $c) {
            $content[] = ['type' => 'text', 'text' => 'Approved cast image: '.$c['name']];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $c['mime'], 'data' => $c['data']]];
        }
        try {
            $r = \App\Services\Create\NetRetry::run(fn () => Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(150)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 3000, 'messages' => [['role' => 'user', 'content' => $content]]]));
            // A refusal or an outage is a vendor event (recorded, alerted when it is our account), and the look says why.
            if (! $r->successful()) {
                $kind = \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status(), ['run_id' => $runId]);
                return ['status' => 'unverified', 'note' => in_array($kind, \App\Services\Vendors\VendorAlerts::OURS, true) ? 'the checking model is unavailable on our side' : 'the checking model did not answer ('.$r->status().')'];
            }
            $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $a = strpos($text, '{'); $b = strrpos($text, '}');
            $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        } catch (\Throwable $e) { Log::warning('Create final look failed', ['run' => $runId, 'error' => mb_substr($e->getMessage(), 0, 200)]); $json = null; }
        if (! is_array($json)) return ['status' => 'unverified'];
        $t = fn ($v) => is_numeric($v) ? round((float) $v, 1) : null;
        $s = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        return ['status' => 'checked', 'has_cast' => (bool) $cast,
            'required' => collect((array) ($json['required'] ?? []))->filter(fn ($x) => is_array($x))->map(fn ($x) => ['id' => preg_match('/^r\d{1,2}$/', (string) ($x['id'] ?? '')) ? $x['id'] : null, 'item' => $s($x['item'] ?? '', 120), 'status' => in_array($x['status'] ?? '', ['present', 'missing', 'unclear'], true) ? $x['status'] : 'unclear', 'time' => $t($x['time'] ?? null), 'note' => $s($x['note'] ?? '', 120)])->take(8)->values()->all(),
            'identity' => ['status' => in_array($json['identity']['status'] ?? '', ['consistent', 'drift', 'unclear', 'none'], true) ? $json['identity']['status'] : 'unclear', 'times' => array_values(array_filter(array_map($t, (array) ($json['identity']['times'] ?? [])), fn ($v) => $v !== null)), 'note' => $s($json['identity']['note'] ?? '', 160)],
            'lettering' => ['status' => ($json['lettering']['status'] ?? '') === 'garbled' ? 'garbled' : 'clean', 'times' => array_values(array_filter(array_map($t, (array) ($json['lettering']['times'] ?? [])), fn ($v) => $v !== null)), 'note' => $s($json['lettering']['note'] ?? '', 160)],
            'answer' => ['status' => in_array($json['answer']['status'] ?? '', ['answered', 'not_answered', 'unclear', 'none'], true) ? $json['answer']['status'] : 'unclear', 'note' => $s($json['answer']['note'] ?? '', 160)],
            'actions' => collect((array) ($json['actions'] ?? []))->filter(fn ($x) => is_array($x))->map(fn ($x) => ['shot' => (int) ($x['shot'] ?? 0), 'status' => in_array($x['status'] ?? '', ['present', 'missing', 'unclear'], true) ? $x['status'] : 'unclear', 'time' => $t($x['time'] ?? null), 'note' => $s($x['note'] ?? '', 120)])->take(12)->values()->all()];
    }
}
