<?php
namespace App\Services\Create;

use App\Models\Asset;
use App\Models\User;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A file is attached without saying what it is for. When the brief is sent, each such file ('auto') becomes a
 * reference (studied for its format and look, never shown), a source (placed in the video) or current (a picture of
 * the user's own current video: they are pointing at a moment to change). One quick model call reads the brief and
 * LOOKS at each file (a video as a sheet of its frames, and the current video's frames when there is one); it also
 * notes what each file is, what the user wants from it, and, only when the prompt leaves that genuinely open, one
 * question with suggested answers. When the role itself is unclear the user is asked "use it, or make mine like it?".
 */
class AttachmentRoles
{
    public const MODEL = 'claude-haiku-4-5-20251001';
    public const ASK_PREFIX = 'role:';
    public const FILE_PREFIX = 'file:';
    public const KINDS = ['logo', 'product_photo', 'photo', 'screenshot', 'illustration', 'character', 'clip_speech', 'clip', 'audio', 'other'];

    /**
     * Settles every 'auto' attachment it can. Returns ['roles' => asset_id => purpose (settled now), 'unsure' => [asset_id, ...]].
     * $answer: the user's reply to a role question, [asset_id, text]; $skip: take the usual role instead of asking.
     */
    public function resolve(User $user, object $c, ?array $answer = null, bool $skip = false): array
    {
        $open = DB::table('create_attachments')->where('conversation_id', $c->id)->where('purpose', 'auto')->orderBy('id')->pluck('asset_id')->map(fn ($a) => (int) $a)->all();
        if (! $open) return ['roles' => [], 'unsure' => []];
        $files = Asset::where('workspace_id', $user->workspace_id)->whereIn('id', $open)->get()->keyBy('id');
        $kind = (json_decode((string) $c->settings_json, true) ?: [])['output_kind'] ?? 'video';
        $roles = []; $notes = [];
        // A reply to "use it, or make mine like it?" decides that file first.
        if ($answer && in_array((int) $answer[0], $open, true) && ($r = self::fromAnswer((string) $answer[1]))) $roles[(int) $answer[0]] = $r;
        $rest = array_values(array_diff($open, array_keys($roles)));
        if ($rest) {
            $briefs = DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'user')->orderBy('sequence')->pluck('content')->implode("\n\n");
            $asked = $this->ask($briefs, array_values(array_filter(array_map(fn ($id) => $files->get($id), $rest))), $kind, $this->currentSheet($c));
            foreach ($rest as $id) {
                $notes[$id] = $asked[$id] ?? [];
                $r = $notes[$id]['role'] ?? null;
                // An answered question that still reads as unclear takes the safe choice: a reference never shows
                // someone else's content in the user's video.
                if ($r === 'unsure' && $answer && (int) $answer[0] === $id) $r = 'reference';
                if ($r === 'unsure' && $skip) $r = null;
                $roles[$id] = $r === 'unsure' ? 'unsure' : ($r ?? self::fallback($files->get($id)?->asset_type, $kind));
            }
        }
        $settled = array_filter($roles, fn ($r) => $r !== 'unsure');
        foreach ($settled as $id => $purpose) DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $id)->where('purpose', 'auto')
            ->update(['purpose' => $purpose, 'updated_at' => now()] + (($n = array_diff_key($notes[$id] ?? [], ['role' => 1])) ? ['notes_json' => json_encode($n)] : []));
        return ['roles' => $settled, 'unsure' => array_keys(array_filter($roles, fn ($r) => $r === 'unsure'))];
    }

    public static function fallback(?string $type, string $kind = 'video'): string
    {
        return $kind === 'video' && $type === 'video' ? 'reference' : 'source';
    }

    /** The question for a file whose role is unclear. */
    public static function question(string $title): string
    {
        return 'Should I put "'.$title.'" in your video, or make your video like it?';
    }

    /** A reply to that question: "use it" or "make mine like it", in the answer cards' words or the user's own. */
    public static function fromAnswer(string $text): ?string
    {
        $t = mb_strtolower($text);
        $use = (bool) preg_match('/\b(use it|put it|in (my|the) video|include it|it\'?s (mine|my own)|my (own )?(footage|clip|video|recording))\b/u', $t);
        $like = (bool) preg_match('/\b(like it|like this|its style|the style|as a reference|reference|follow it|copy it|similar|inspir)/u', $t);
        return $use === $like ? null : ($use ? 'source' : 'reference');
    }

    /** @return array<int, array>|null asset_id => ['role' => source|reference|current|unsure, 'kind', 'use', 'time', 'ask', 'options'] */
    private function ask(string $briefs, array $assets, string $kind, ?string $current = null): ?array
    {
        if (! $assets || config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '') return null;
        $content = [];
        foreach (array_slice($assets, 0, 6) as $a) {
            $content[] = ['type' => 'text', 'text' => 'File id '.$a->id.': "'.$a->title.'", '.$a->asset_type.($a->duration_seconds ? ', '.round((float) $a->duration_seconds).' s' : '')];
            if ($image = $this->look($a)) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image[0], 'data' => base64_encode($image[1])]];
        }
        if ($current) {
            $content[] = ['type' => 'text', 'text' => "The user's CURRENT video (the version they have now), one frame per second: cells left to right, then down, at 0.5 s, 1.5 s, 2.5 s and so on."];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($current)]];
        }
        $content[] = ['type' => 'text', 'text' => 'A user attached these files (each shown above, a video as a sheet of its frames) to a request for a short '.$kind.". For each file decide its role:\n"
            ."\"source\": the file itself appears in the result (their own footage, product photo, logo, app demo or screen recording, voiceover, music).\n"
            ."\"reference\": the result should be like it (a format, style, pacing or look to follow); nothing from it is shown. \"I want this video for my product\", \"like this\", \"same format\" mean reference.\n"
            .($current ? "\"current\": a picture of the user's own current video (shown above): a screenshot of one of its frames, possibly with a phone or browser around it. They are pointing at that moment to change it. Give its time in seconds.\n" : '')
            ."\"unsure\": what they wrote and what the file shows do not settle it, and guessing wrong would matter. Prefer unsure over a guess.\n"
            ."A finished ad or post for a different brand or product than the user's is a reference unless they say to use it.\n"
            ."Also say what each file is (kind: ".implode(', ', self::KINDS)."), what the user wants from it in a few words from what they wrote (use; empty when they did not say), and only when its use is genuinely open and it matters (where a logo goes, which moment a clip fills, what to change on a current frame), one short question (ask, under 14 words) with 2 to 4 short suggested answers (options). Infer whatever you can; never ask what the prompt or the file already settles.\n"
            .'Reply with JSON only: {"files": {"<id>": {"role": "source" | "reference"'.($current ? ' | "current"' : '').' | "unsure", "kind": string, "use": string, "time": number | null, "ask": string | null, "options": [string]}}}'
            ."\n\nWhat the user wrote:\n".mb_substr($briefs, 0, 6000)];
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 300, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) { Log::warning('Create attachment roles: model call failed', ['status' => $r->status()]); \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status()); return null; }
            PlanningCosts::call('files', self::MODEL, (array) $r->json('usage', []));
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $start = strpos($raw, '{');
            $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
            $ids = array_map(fn ($a) => (int) $a->id, $assets);
            $roles = $current ? ['source', 'reference', 'current', 'unsure'] : ['source', 'reference', 'unsure'];
            // The older reply shape ({"roles": {"<id>": "source"}}) is still read.
            $raw = isset($json['files']) ? (array) $json['files'] : array_map(fn ($r) => ['role' => $r], (array) ($json['roles'] ?? []));
            $str = fn ($v, $n) => is_string($v) ? mb_substr(trim($v), 0, $n) : '';
            return collect($raw)->mapWithKeys(fn ($v, $k) => [(int) $k => is_array($v) ? $v : ['role' => $v]])
                ->filter(fn ($v, $k) => in_array($k, $ids, true) && in_array($v['role'] ?? null, $roles, true))
                ->map(fn ($v) => array_filter(['role' => $v['role'], 'kind' => in_array($v['kind'] ?? null, self::KINDS, true) ? $v['kind'] : null, 'use' => $str($v['use'] ?? '', 120) ?: null,
                    'time' => is_numeric($v['time'] ?? null) && $v['role'] === 'current' ? round(max(0, (float) $v['time']), 1) : null,
                    'ask' => $str($v['ask'] ?? '', 120) ?: null,
                    'options' => array_values(array_slice(array_filter(array_map(fn ($o) => $str($o, 40), (array) ($v['options'] ?? []))), 0, 4)) ?: null], fn ($x) => $x !== null))->all();
        } catch (\Throwable $e) {
            Log::warning('Create attachment roles: '.mb_substr($e->getMessage(), 0, 200));
            return null;
        }
    }

    /** What the model sees of a file: an image as itself (up to 1 MB), a video as a sheet of its frames. */
    /** The user's current video (the head version), one frame a second tiled 5 across: what a screenshot is matched to. */
    private function currentSheet(object $c): ?string
    {
        try {
            $path = $c->head_revision_id ? DB::table('composition_revisions')->where('id', $c->head_revision_id)->value('artifact_path') : null;
            if (! $path || ! str_ends_with((string) $path, '.mp4')) return null;
            $storage = app(CreateStorage::class);
            if (! $storage->exists($path)) return null;
            $dir = sys_get_temp_dir().'/current-'.bin2hex(random_bytes(6)); @mkdir($dir, 0700, true);
            $r = \Illuminate\Support\Facades\Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-ss', '0.5', '-i', $storage->path($path), '-vf', 'fps=1,scale=200:-2,tile=5x6', '-frames:v', '1', '-q:v', '5', $dir.'/sheet.jpg']);
            $bytes = $r->successful() && is_file($dir.'/sheet.jpg') ? (string) file_get_contents($dir.'/sheet.jpg') : null;
            @unlink($dir.'/sheet.jpg'); @rmdir($dir);
            return $bytes ?: null;
        } catch (\Throwable) { return null; }
    }

    /** The first question a file's reading left open and that has not been asked yet: [asset_id, question, options] or null. */
    public static function pendingAsk(string $conversationId): ?array
    {
        foreach (DB::table('create_attachments')->where('conversation_id', $conversationId)->whereNotNull('notes_json')->orderBy('id')->get(['asset_id', 'notes_json']) as $a) {
            $n = json_decode((string) $a->notes_json, true) ?: [];
            if (empty($n['ask'])) continue;
            if (DB::table('create_messages')->where('conversation_id', $conversationId)->where('idempotency_key', 'like', self::FILE_PREFIX.$a->asset_id.':%')->exists()) continue;
            return [(int) $a->asset_id, (string) $n['ask'], (array) ($n['options'] ?? [])];
        }
        return null;
    }

    private function look(Asset $a): ?array
    {
        try {
            if ($a->asset_type === 'video') {
                $path = app(References\ReferenceSheets::class)->pathFor($a);
                return $path ? ['image/jpeg', (string) app(\App\Services\Create\CreateStorage::class)->get($path)] : null;
            }
            if ($a->asset_type === 'image' && in_array($a->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $bytes = app(StorageService::class)->get((string) $a->storage_url);
                return is_string($bytes) && $bytes !== '' && strlen($bytes) <= 1_000_000 ? [$a->mime_type, $bytes] : null;
            }
        } catch (\Throwable) {}
        return null;
    }
}
