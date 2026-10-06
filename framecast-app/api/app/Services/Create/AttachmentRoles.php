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
 * reference (studied for its format and look, never shown) or a source (placed in the video). One quick model call
 * reads the brief and LOOKS at each file (a video as a sheet of its frames); when the brief does not make a file's
 * role clear, the file is left undecided and the user is asked, one file at a time: "use it, or make mine like it?".
 */
class AttachmentRoles
{
    public const MODEL = 'claude-haiku-4-5-20251001';
    public const ASK_PREFIX = 'role:';

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
        $roles = [];
        // A reply to "use it, or make mine like it?" decides that file first.
        if ($answer && in_array((int) $answer[0], $open, true) && ($r = self::fromAnswer((string) $answer[1]))) $roles[(int) $answer[0]] = $r;
        $rest = array_values(array_diff($open, array_keys($roles)));
        if ($rest) {
            $briefs = DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'user')->orderBy('sequence')->pluck('content')->implode("\n\n");
            $asked = $this->ask($briefs, array_values(array_filter(array_map(fn ($id) => $files->get($id), $rest))), $kind);
            foreach ($rest as $id) {
                $r = $asked[$id] ?? null;
                // An answered question that still reads as unclear takes the safe choice: a reference never shows
                // someone else's content in the user's video.
                if ($r === 'unsure' && $answer && (int) $answer[0] === $id) $r = 'reference';
                if ($r === 'unsure' && $skip) $r = null;
                $roles[$id] = $r === 'unsure' ? 'unsure' : ($r ?? self::fallback($files->get($id)?->asset_type, $kind));
            }
        }
        $settled = array_filter($roles, fn ($r) => $r !== 'unsure');
        foreach ($settled as $id => $purpose) DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $id)->where('purpose', 'auto')->update(['purpose' => $purpose, 'updated_at' => now()]);
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

    /** @return array<int, string>|null asset_id => 'source'|'reference'|'unsure' */
    private function ask(string $briefs, array $assets, string $kind): ?array
    {
        if (! $assets || config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '') return null;
        $content = [];
        foreach (array_slice($assets, 0, 6) as $a) {
            $content[] = ['type' => 'text', 'text' => 'File id '.$a->id.': "'.$a->title.'", '.$a->asset_type.($a->duration_seconds ? ', '.round((float) $a->duration_seconds).' s' : '')];
            if ($image = $this->look($a)) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image[0], 'data' => base64_encode($image[1])]];
        }
        $content[] = ['type' => 'text', 'text' => 'A user attached these files (each shown above, a video as a sheet of its frames) to a request for a short '.$kind.". For each file decide its role:\n"
            ."\"source\": the file itself appears in the result (their own footage, product photo, logo, app demo or screen recording, voiceover, music).\n"
            ."\"reference\": the result should be like it (a format, style, pacing or look to follow); nothing from it is shown. \"I want this video for my product\", \"like this\", \"same format\" mean reference.\n"
            ."\"unsure\": what they wrote and what the file shows do not settle it, and guessing wrong would matter. Prefer unsure over a guess.\n"
            ."A finished ad or post for a different brand or product than the user's is a reference unless they say to use it.\n"
            .'Reply with JSON only: {"roles": {"<id>": "source" | "reference" | "unsure"}}'
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
            return collect((array) ($json['roles'] ?? []))->mapWithKeys(fn ($v, $k) => [(int) $k => $v])
                ->filter(fn ($v, $k) => in_array($k, $ids, true) && in_array($v, ['source', 'reference', 'unsure'], true))->all();
        } catch (\Throwable $e) {
            Log::warning('Create attachment roles: '.mb_substr($e->getMessage(), 0, 200));
            return null;
        }
    }

    /** What the model sees of a file: an image as itself (up to 1 MB), a video as a sheet of its frames. */
    private function look(Asset $a): ?array
    {
        try {
            if ($a->asset_type === 'video') {
                $path = app(References\ReferenceSheets::class)->pathFor($a);
                return $path ? ['image/jpeg', (string) Storage::disk('local')->get($path)] : null;
            }
            if ($a->asset_type === 'image' && in_array($a->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $bytes = app(StorageService::class)->get((string) $a->storage_url);
                return is_string($bytes) && $bytes !== '' && strlen($bytes) <= 1_000_000 ? [$a->mime_type, $bytes] : null;
            }
        } catch (\Throwable) {}
        return null;
    }
}
