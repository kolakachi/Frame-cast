<?php
namespace App\Services\Create;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Log};
use Illuminate\Support\Str;

/**
 * Documents in Weave (2026-10-09): a PDF, Word or PowerPoint file added to a conversation. The extract service reads
 * its pages (a tall page or picture comes in A4-shaped parts, cut at blank gaps), its pictures and its words; a small
 * model reads the facts. The user ticks the pictures and pages the video may show (they become source attachments);
 * the words always reach the planner as facts. A document with nothing to pick is used for its words alone.
 */
class DocumentService
{
    public const MODEL = 'claude-haiku-4-5-20251001';
    public const EXTENSIONS = ['pdf', 'docx', 'pptx'];
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const PER_CONVERSATION = 5;
    /** Characters of a document's words the planner reads; the summary and facts cover the rest. */
    public const PLAN_TEXT = 12000;
    /** Scanned parts read by the model (a scan has no words to extract). */
    public const SCANNED_READ = 10;

    public function add(User $user, string $conversationId, UploadedFile $file): object
    {
        $service = app(ConversationService::class);
        $service->authorize($user, true);
        app(AdmissionControl::class)->assertOpen();
        $name = mb_substr(basename((string) $file->getClientOriginalName()), 0, 200) ?: 'document';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        abort_unless($file->isValid() && in_array($ext, self::EXTENSIONS, true), 422, 'Use a PDF, Word (.docx) or PowerPoint (.pptx) file.');
        abort_unless((int) $file->getSize() > 0 && (int) $file->getSize() <= self::MAX_BYTES, 422, 'Use a document up to 20 MB.');
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 5);
        abort_unless($ext === 'pdf' ? $head === '%PDF-' : str_starts_with($head, 'PK'), 422, 'That file is not a readable '.($ext === 'pdf' ? 'PDF' : ($ext === 'docx' ? 'Word file' : 'PowerPoint file')).'.');
        $id = (string) Str::uuid();
        $path = 'create/documents/'.$user->workspace_id.'/'.$id.'.'.$ext;
        DB::transaction(function () use ($user, $conversationId, $service, $file, $id, $path, $name, $ext) {
            $c = $service->conversation($user, $conversationId, true);
            abort_if($c->archived_at, 409, 'Restore this conversation before adding files.');
            abort_if(DB::table('create_documents')->where('conversation_id', $conversationId)->count() >= self::PER_CONVERSATION, 422, 'Use at most '.self::PER_CONVERSATION.' documents in one conversation.');
            $stream = fopen($file->getRealPath(), 'rb');
            try { abort_unless(app(CreateStorage::class)->put($path, $stream, ['visibility' => 'private']), 503, 'Upload storage is unavailable.'); }
            finally { if (is_resource($stream)) fclose($stream); }
            DB::table('create_documents')->insert(['id' => $id, 'workspace_id' => $user->workspace_id, 'conversation_id' => $conversationId, 'user_id' => $user->id,
                'title' => $name, 'source' => $ext, 'path' => $path, 'status' => 'reading', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('create_conversations')->where('id', $conversationId)->update(['version' => $c->version + 1, 'updated_at' => now()]);
        });
        \App\Jobs\ReadCreateDocument::dispatch($id);
        return DB::table('create_documents')->where('id', $id)->first();
    }

    /** The background read: pages, parts, pictures (with small thumbnails for the drawer), words and facts. */
    public function readNow(string $id): void
    {
        $d = DB::table('create_documents')->where('id', $id)->first();
        if (! $d || $d->status !== 'reading') return;
        $fail = fn (string $why) => DB::table('create_documents')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($why, 0, 255), 'updated_at' => now()]);
        $bytes = app(CreateStorage::class)->get($d->path);
        if ($bytes === null) { $fail('The document file is missing. Add it again.'); return; }
        $r = Http::timeout(300)->attach('file', $bytes, $d->title)->post(self::base().'/extract/document', ['filename' => $d->title]);
        if (! $r->successful()) { $fail($r->status() === 422 ? (string) ($r->json('detail') ?: 'Could not read that document.') : 'Could not read that document.'); return; }
        $a = (array) $r->json();
        // A Word or PowerPoint file is kept as the PDF it was turned into, so every later render reads the same pages.
        if (! empty($a['pdf_base64'])) {
            $pdfPath = preg_replace('/\.(docx|pptx)$/', '.pdf', $d->path);
            if (app(CreateStorage::class)->put($pdfPath, base64_decode($a['pdf_base64']), ['visibility' => 'private'])) {
                app(CreateStorage::class)->delete($d->path);
                DB::table('create_documents')->where('id', $id)->update(['path' => $pdfPath]);
                $d->path = $pdfPath;
            }
        }
        unset($a['pdf_base64']);
        $text = collect($a['pages'] ?? [])->filter(fn ($p) => trim((string) ($p['text'] ?? '')) !== '')
            ->map(fn ($p) => '[page '.$p['number'].']'."\n".trim((string) $p['text']))->implode("\n\n");
        // The words are kept once, in text; the stored analysis keeps what the drawer shows.
        $a['pages'] = array_map(fn ($p) => array_diff_key($p, ['text' => 1]), (array) ($a['pages'] ?? []));
        $reading = $this->facts($d, $text, $a);
        DB::table('create_documents')->where('id', $id)->update(['status' => 'ready', 'page_count' => (int) ($a['page_count'] ?? 0),
            'analysis_json' => json_encode($a), 'text' => $text !== '' ? $text : null, 'facts_json' => $reading ? json_encode($reading) : null, 'updated_at' => now()]);
    }

    /**
     * What the document says, read by a small model: a short summary and up to ten facts with their pages, quoted as
     * the document states them. Scanned parts are shown as pictures. The cost is billed with the conversation's next plan.
     */
    private function facts(object $d, string $text, array $a): ?array
    {
        if (config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '') return null;
        $scanned = collect($a['pages'] ?? [])->filter(fn ($p) => ($p['kind'] ?? '') === 'scanned')
            ->flatMap(fn ($p) => array_map(fn ($part) => ['kind' => 'page', 'number' => $p['number'], 'part' => $part['part']], (array) ($p['parts'] ?? [])))
            ->take(self::SCANNED_READ)->values()->all();
        if ($text === '' && ! $scanned) return null;
        $content = [];
        foreach ($scanned ? $this->render($d, $scanned) : [] as $img) {
            $content[] = ['type' => 'text', 'text' => 'Scanned '.preg_replace('/^page-(\d+)-(\d+)$/', 'page $1, part $2', (string) $img['key']).':'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $img['base64']]];
        }
        $content[] = ['type' => 'text', 'text' => 'A business uploaded this document ("'.$d->title.'") to make a short marketing video from it.'
            .($text !== '' ? "\n\nIts words, by page:\n".mb_substr($text, 0, 40000) : '')
            ."\n\nReply with JSON only: {\"summary\": string (what the document is and what it offers, under 60 words), \"facts\": [{\"text\": string, \"page\": number}]}"
            ."\nfacts: up to 10 of the most useful statements for a video (the product or offer, who it is for, prices, numbers, results, dates, the call to action), each under 20 words, as the document states them. Copy numbers, prices and names exactly. Nothing the document does not say."];
        PlanningCosts::begin($d->conversation_id);
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 900, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) { Log::warning('Create document: facts call failed', ['status' => $r->status()]); \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status()); return null; }
            PlanningCosts::call('document', self::MODEL, (array) $r->json('usage', []));
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $start = strpos($raw, '{');
            $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
            if (! is_array($json)) return null;
            $facts = collect((array) ($json['facts'] ?? []))->filter(fn ($f) => is_array($f) && is_string($f['text'] ?? null) && trim($f['text']) !== '')
                ->map(fn ($f) => ['text' => mb_substr(trim($f['text']), 0, 200), 'page' => is_numeric($f['page'] ?? null) ? (int) $f['page'] : null])->take(10)->values()->all();
            return ['summary' => is_string($json['summary'] ?? null) ? mb_substr(trim($json['summary']), 0, 500) : '', 'facts' => $facts];
        } catch (\Throwable $e) {
            Log::warning('Create document: facts call failed', ['error' => $e->getMessage()]);
            return null;
        } finally { PlanningCosts::end(); }
    }

    /**
     * The ticked pictures and pages become pictures in the conversation (source attachments), each noted with the
     * document and page it came from. $picks: [{kind: picture, id, part} | {kind: page, number, part}].
     */
    public function useParts(User $user, string $conversationId, string $docId, array $picks, int $version): void
    {
        $service = app(ConversationService::class);
        $service->authorize($user, true);
        $d = $this->document($user, $conversationId, $docId);
        abort_unless($d->status === 'ready', 409, 'That document is still being read.');
        $a = json_decode((string) $d->analysis_json, true) ?: [];
        $valid = collect($picks)->filter(fn ($p) => match ($p['kind'] ?? null) {
            'picture' => collect($a['pictures'] ?? [])->contains(fn ($x) => $x['id'] === ($p['id'] ?? null) && (int) ($p['part'] ?? 1) <= count($x['parts'] ?? [1])),
            'page' => collect($a['pages'] ?? [])->contains(fn ($x) => (int) $x['number'] === (int) ($p['number'] ?? 0) && (int) ($p['part'] ?? 1) <= count($x['parts'] ?? [1])),
            default => false,
        })->map(fn ($p) => ($p['kind'] === 'picture' ? ['kind' => 'picture', 'id' => (string) $p['id']] : ['kind' => 'page', 'number' => (int) $p['number']]) + ['part' => max(1, (int) ($p['part'] ?? 1))])
            ->unique(fn ($p) => json_encode($p))->values()->all();
        $used = DB::table('create_attachments')->where('conversation_id', $conversationId)->count();
        abort_if($used + count($valid) > 20, 422, 'A conversation holds 20 files. You have room for '.max(0, 20 - $used).' more.');
        abort_unless((int) DB::table('create_conversations')->where('id', $conversationId)->value('version') === $version, 409, 'Conversation changed. Refresh first.');
        if ($valid) {
            $images = collect($this->render($d, $valid))->keyBy('key');
            foreach ($valid as $p) {
                $key = $p['kind'] === 'picture' ? 'picture-'.$p['id'].'-'.$p['part'] : 'page-'.$p['number'].'-'.$p['part'];
                $img = $images->get($key);
                if (! $img) continue;
                $page = $p['kind'] === 'picture' ? (int) (collect($a['pictures'])->firstWhere('id', $p['id'])['page'] ?? 0) : $p['number'];
                $of = $p['kind'] === 'picture' ? count(collect($a['pictures'])->firstWhere('id', $p['id'])['parts'] ?? [1]) : count(collect($a['pages'])->firstWhere('number', $p['number'])['parts'] ?? [1]);
                $label = ($p['kind'] === 'picture' ? 'Picture' : 'Page '.$page).($p['kind'] === 'picture' ? ', page '.$page : '').($of > 1 ? ' (part '.$p['part'].' of '.$of.')' : '');
                $tmp = tempnam(sys_get_temp_dir(), 'doc');
                file_put_contents($tmp, base64_decode($img['base64']));
                try {
                    $file = new UploadedFile($tmp, mb_substr(pathinfo($d->title, PATHINFO_FILENAME), 0, 120).' · '.$label.'.jpg', 'image/jpeg', null, true);
                    $current = (int) DB::table('create_conversations')->where('id', $conversationId)->value('version');
                    $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, $file, 'source', 'doc:'.$d->id.':'.$key, $current);
                    // A whole page usually carries a chart, a table or a layout; a picture is a photo or an illustration.
                    DB::table('create_attachments')->where('conversation_id', $conversationId)->where('asset_id', $asset->id)->update(['notes_json' => json_encode([
                        'kind' => $p['kind'] === 'page' ? 'document_page' : 'document_picture', 'use' => 'From '.$d->title.', '.lcfirst($label), 'document_id' => $d->id]), 'updated_at' => now()]);
                } finally { @unlink($tmp); }
            }
        }
        DB::table('create_documents')->where('id', $d->id)->update(['picks_json' => json_encode($valid), 'updated_at' => now()]);
    }

    public function remove(User $user, string $conversationId, string $docId): void
    {
        app(ConversationService::class)->authorize($user, true);
        $d = $this->document($user, $conversationId, $docId);
        DB::table('create_documents')->where('id', $d->id)->delete();
        DB::table('create_conversations')->where('id', $conversationId)->increment('version');
        app(CreateStorage::class)->delete($d->path);
    }

    public function document(User $user, string $conversationId, string $docId): object
    {
        app(ConversationService::class)->conversation($user, $conversationId);
        $d = DB::table('create_documents')->where('id', $docId)->where('conversation_id', $conversationId)->where('workspace_id', $user->workspace_id)->first();
        abort_unless($d, 404);
        return $d;
    }

    /** The chip: small enough for every conversation refresh (no thumbnails). */
    public static function brief(object $d): array
    {
        $a = json_decode((string) $d->analysis_json, true) ?: [];
        $facts = json_decode((string) $d->facts_json, true) ?: [];
        // A read that never finished (a worker restarted under it) is shown as failed, so the composer is not held.
        if ($d->status === 'reading' && \Illuminate\Support\Carbon::parse($d->updated_at)->lt(now()->subMinutes(10))) { $d->status = 'failed'; $d->error = 'Reading took too long. Remove it and add it again.'; }
        return ['id' => $d->id, 'title' => $d->title, 'source' => $d->source, 'status' => $d->status, 'error' => $d->error, 'page_count' => (int) $d->page_count, 'created_at' => $d->created_at,
            'pictures' => count($a['pictures'] ?? []), 'text_only' => (bool) ($a['text_only'] ?? false), 'chosen' => $d->picks_json !== null,
            'picked' => count(json_decode((string) $d->picks_json, true) ?: []), 'summary' => $facts['summary'] ?? '', 'facts' => $facts['facts'] ?? []];
    }

    /** The drawer: every picture and page part with its thumbnail. */
    public static function full(object $d): array
    {
        $a = json_decode((string) $d->analysis_json, true) ?: [];
        return self::brief($d) + ['picks' => json_decode((string) $d->picks_json, true) ?: [],
            'pages' => array_map(fn ($p) => ['number' => $p['number'], 'kind' => $p['kind'], 'graphic' => ($p['drawings'] ?? 0) >= 12, 'parts' => $p['parts']], (array) ($a['pages'] ?? [])),
            'picture_list' => array_values((array) ($a['pictures'] ?? []))];
    }

    public static function forConversation(string $conversationId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('create_documents')) return [];
        return DB::table('create_documents')->where('conversation_id', $conversationId)->orderBy('created_at')->get()->map(fn ($d) => self::brief($d))->all();
    }

    /** What the planner reads: each ready document's summary, facts and words (the first PLAN_TEXT characters). */
    public static function forPlan(string $conversationId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('create_documents')) return [];
        return DB::table('create_documents')->where('conversation_id', $conversationId)->where('status', 'ready')->orderBy('created_at')->get()
            ->map(function ($d) {
                $f = json_decode((string) $d->facts_json, true) ?: [];
                return array_filter(['title' => $d->title, 'pages' => (int) $d->page_count, 'summary' => $f['summary'] ?? null,
                    'facts' => array_map(fn ($x) => $x['text'].($x['page'] ? ' (page '.$x['page'].')' : ''), $f['facts'] ?? []),
                    'text' => $d->text ? mb_substr((string) $d->text, 0, self::PLAN_TEXT).(mb_strlen((string) $d->text) > self::PLAN_TEXT ? "\n[… the rest is covered by the summary and facts]" : '') : null]);
            })->all();
    }

    private function render(object $d, array $items): array
    {
        $bytes = app(CreateStorage::class)->get($d->path);
        abort_if($bytes === null, 410, 'The document file is missing. Add it again.');
        $r = Http::timeout(110)->attach('file', $bytes, basename($d->path))->post(self::base().'/extract/document/render', ['filename' => basename($d->path), 'items' => json_encode($items)]);
        abort_unless($r->successful(), 502, 'Could not take those pages from the document. Try again.');
        return (array) $r->json('images', []);
    }

    private static function base(): string { return rtrim((string) config('services.extract.url'), '/'); }
}
