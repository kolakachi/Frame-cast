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

    /** How a document may be used (the drawer's question; owner, 2026-10-09: nothing chosen until the user picks). */
    public const MODES = ['auto', 'pages', 'pictures', 'notes', 'words'];
    /** Let Weave choose: the most pictures offered, and the smallest worth offering (pixels a side). */
    public const AUTO_PICTURES = 8;
    public const AUTO_MIN_SIDE = 200;

    /**
     * The drawer's answer. $modes: auto (Weave chooses: its larger pictures are offered for the planner to use where
     * they fit, and its words shape the plan), pages (shown as they look), pictures (the ticked ones, used on their
     * own), notes (a deck's speaker notes are the script), words (its words only). The ticked pictures and pages become pictures in the conversation
     * (source attachments), each noted with the document and page it came from: [{kind: picture, id, part} | {kind:
     * page, number, part}]. The words are read whatever is chosen.
     */
    public function useParts(User $user, string $conversationId, string $docId, array $modes, array $picks, int $version): void
    {
        $service = app(ConversationService::class);
        $service->authorize($user, true);
        $d = $this->document($user, $conversationId, $docId);
        abort_unless($d->status === 'ready', 409, 'That document is still being read.');
        $a = json_decode((string) $d->analysis_json, true) ?: [];
        $modes = array_values(array_intersect(self::MODES, $modes));
        if (in_array('words', $modes, true)) $modes = ['words'];
        // Letting Weave choose the pictures replaces ticking them.
        if (in_array('auto', $modes, true)) $modes = array_values(array_diff($modes, ['pictures']));
        if (empty($a['notes'])) $modes = array_values(array_diff($modes, ['notes']));
        abort_unless($modes, 422, 'Choose how Weave should use this document.');
        $picks = array_values(array_filter($picks, fn ($p) => ($p['kind'] ?? null) === 'page' ? in_array('pages', $modes, true) : (($p['kind'] ?? null) === 'picture' && in_array('pictures', $modes, true))));
        $valid = collect($picks)->filter(fn ($p) => match ($p['kind'] ?? null) {
            'picture' => collect($a['pictures'] ?? [])->contains(fn ($x) => $x['id'] === ($p['id'] ?? null) && (int) ($p['part'] ?? 1) <= count($x['parts'] ?? [1])),
            'page' => collect($a['pages'] ?? [])->contains(fn ($x) => (int) $x['number'] === (int) ($p['number'] ?? 0) && (int) ($p['part'] ?? 1) <= count($x['parts'] ?? [1])),
            default => false,
        })->map(fn ($p) => ($p['kind'] === 'picture' ? ['kind' => 'picture', 'id' => (string) $p['id']] : ['kind' => 'page', 'number' => (int) $p['number']]) + ['part' => max(1, (int) ($p['part'] ?? 1))])
            ->unique(fn ($p) => json_encode($p))->values()->all();
        $used = DB::table('create_attachments')->where('conversation_id', $conversationId)->count();
        // Weave chooses: the larger pictures (each part of a tall one), biggest first, as many as there is room for.
        $offered = [];
        if (in_array('auto', $modes, true)) {
            $room = min(self::AUTO_PICTURES, 20 - $used - count($valid));
            foreach (collect($a['pictures'] ?? [])->filter(fn ($x) => min((int) $x['width'], (int) $x['height']) >= self::AUTO_MIN_SIDE)->sortByDesc(fn ($x) => (int) $x['width'] * (int) $x['height']) as $x)
                foreach ($x['parts'] ?? [['part' => 1]] as $part) if (count($offered) < $room) $offered[] = ['kind' => 'picture', 'id' => (string) $x['id'], 'part' => (int) $part['part'], 'optional' => true];
        }
        abort_if($used + count($valid) > 20, 422, 'A conversation holds 20 files. You have room for '.max(0, 20 - $used).' more.');
        abort_unless((int) DB::table('create_conversations')->where('id', $conversationId)->value('version') === $version, 409, 'Conversation changed. Refresh first.');
        $added = [];
        if ($valid || $offered) {
            $all = array_merge($valid, $offered);
            $images = collect($this->render($d, array_map(fn ($p) => array_diff_key($p, ['optional' => 1]), $all)))->keyBy('key');
            foreach ($all as $p) {
                $key = $p['kind'] === 'picture' ? 'picture-'.$p['id'].'-'.$p['part'] : 'page-'.$p['number'].'-'.$p['part'];
                $img = $images->get($key);
                if (! $img) continue;
                $page = $p['kind'] === 'picture' ? (int) (collect($a['pictures'])->firstWhere('id', $p['id'])['page'] ?? 0) : $p['number'];
                $of = $p['kind'] === 'picture' ? count(collect($a['pictures'])->firstWhere('id', $p['id'])['parts'] ?? [1]) : count(collect($a['pages'])->firstWhere('number', $p['number'])['parts'] ?? [1]);
                $label = ($p['kind'] === 'picture' ? 'Picture' : ($d->source === 'pptx' ? 'Slide ' : 'Page ').$page).($p['kind'] === 'picture' ? ', page '.$page : '').($of > 1 ? ' (part '.$p['part'].' of '.$of.')' : '');
                $tmp = tempnam(sys_get_temp_dir(), 'doc');
                file_put_contents($tmp, base64_decode($img['base64']));
                try {
                    $file = new UploadedFile($tmp, mb_substr(pathinfo($d->title, PATHINFO_FILENAME), 0, 120).' · '.$label.'.jpg', 'image/jpeg', null, true);
                    $current = (int) DB::table('create_conversations')->where('id', $conversationId)->value('version');
                    $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, $file, 'source', 'doc:'.$d->id.':'.$key, $current);
                    // A page or slide is shown as it looks; a picture is used on its own, like an uploaded photo.
                    // An offered picture (Weave chooses) is used only where it fits.
                    DB::table('create_attachments')->where('conversation_id', $conversationId)->where('asset_id', $asset->id)->update(['notes_json' => json_encode([
                        'kind' => $p['kind'] === 'page' ? 'document_page' : 'document_picture', 'use' => 'From '.$d->title.', '.lcfirst($label).(! empty($p['optional']) ? ' (use it where it fits)' : ''), 'document_id' => $d->id]
                        + (! empty($p['optional']) ? ['optional' => true] : [])), 'updated_at' => now()]);
                    if (! empty($p['optional'])) $added[] = ['asset_id' => (int) $asset->id, 'bytes' => base64_decode($img['base64'])];
                } finally { @unlink($tmp); }
            }
        }
        // The planner sees the offered pictures as one sheet, so it can tell which fit.
        $sheet = $added ? $this->sheet($d, $added) : null;
        DB::table('create_documents')->where('id', $d->id)->update(['picks_json' => json_encode(['modes' => $modes, 'picks' => $valid,
            'offered' => array_column($added, 'asset_id'), 'sheet' => $sheet]), 'updated_at' => now()]);
    }

    /** Cells of 360 px, four across, in the order of $pictures; stored beside the document. Null when ffmpeg fails. */
    private function sheet(object $d, array $pictures): ?string
    {
        $dir = sys_get_temp_dir().'/docsheet-'.Str::random(8);
        @mkdir($dir);
        try {
            $args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y'];
            $chain = []; $layout = [];
            foreach ($pictures as $i => $p) {
                file_put_contents($dir.'/'.$i.'.jpg', $p['bytes']);
                array_push($args, '-i', $dir.'/'.$i.'.jpg');
                $chain[] = '['.$i.']scale=352:352:force_original_aspect_ratio=decrease,pad=360:360:(ow-iw)/2:(oh-ih)/2:color=white[c'.$i.']';
                $layout[] = (($i % 4) * 360).'_'.(intdiv($i, 4) * 360);
            }
            $n = count($pictures);
            $graph = implode(';', $chain).';'.($n === 1 ? '[c0]null[out]' : implode('', array_map(fn ($i) => '[c'.$i.']', range(0, $n - 1))).'xstack=inputs='.$n.':layout='.implode('|', $layout).':fill=white[out]');
            array_push($args, '-filter_complex', $graph, '-map', '[out]', '-frames:v', '1', '-q:v', '4', $dir.'/sheet.jpg');
            $r = \Illuminate\Support\Facades\Process::timeout(60)->run($args);
            if (! $r->successful() || ! is_file($dir.'/sheet.jpg')) { Log::warning('Create document: sheet failed', ['error' => mb_substr($r->errorOutput(), 0, 300)]); return null; }
            $path = 'create/documents/'.$d->workspace_id.'/'.$d->id.'-offered.jpg';
            return app(CreateStorage::class)->put($path, (string) file_get_contents($dir.'/sheet.jpg'), ['visibility' => 'private']) ? $path : null;
        } finally { foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
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
        return ['id' => $d->id, 'title' => $d->title, 'source' => $d->source, 'status' => $d->status, 'error' => $d->error, 'page_count' => (int) $d->page_count, 'pages_total' => (int) ($a['pages_total'] ?? $d->page_count), 'created_at' => $d->created_at,
            'pictures' => count($a['pictures'] ?? []), 'notes' => count($a['notes'] ?? []), 'text_only' => (bool) ($a['text_only'] ?? false), 'chosen' => $d->picks_json !== null,
            'modes' => self::choice($d)['modes'], 'picked' => count(self::choice($d)['picks']), 'offered' => count(self::choice($d)['offered']), 'summary' => $facts['summary'] ?? '', 'facts' => $facts['facts'] ?? []];
    }

    /** What the user chose in the drawer: {modes, picks} (an older choice was the list of picks alone). */
    public static function choice(object $d): array
    {
        $c = json_decode((string) $d->picks_json, true);
        if (! is_array($c)) return ['modes' => [], 'picks' => [], 'offered' => [], 'sheet' => null];
        return array_is_list($c) ? ['modes' => $c ? ['pages', 'pictures'] : ['words'], 'picks' => $c, 'offered' => [], 'sheet' => null]
            : ['modes' => (array) ($c['modes'] ?? []), 'picks' => (array) ($c['picks'] ?? []), 'offered' => (array) ($c['offered'] ?? []), 'sheet' => $c['sheet'] ?? null];
    }

    /** The drawer: every picture and page part with its thumbnail. */
    public static function full(object $d): array
    {
        $a = json_decode((string) $d->analysis_json, true) ?: [];
        return self::brief($d) + ['picks' => self::choice($d)['picks'], 'notes_list' => array_values((array) ($a['notes'] ?? [])),
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
                $modes = self::choice($d)['modes'];
                $notes = (array) (json_decode((string) $d->analysis_json, true)['notes'] ?? []);
                return array_filter(['title' => $d->title, 'kind' => $d->source === 'pptx' ? 'slides' : 'document', 'pages' => (int) $d->page_count, 'summary' => $f['summary'] ?? null,
                    // How the user chose to use it: pages (shown as they look), pictures, notes (the script), words.
                    'use' => $modes ?: null,
                    'speaker_notes' => in_array('notes', $modes, true) && $notes ? array_map(fn ($n) => ['slide' => (int) $n['slide'], 'notes' => mb_substr((string) $n['text'], 0, 1200)], $notes) : null,
                    'facts' => array_map(fn ($x) => $x['text'].($x['page'] ? ' (page '.$x['page'].')' : ''), $f['facts'] ?? []),
                    'text' => $d->text ? mb_substr((string) $d->text, 0, self::PLAN_TEXT).(mb_strlen((string) $d->text) > self::PLAN_TEXT ? "\n[… the rest is covered by the summary and facts]" : '') : null]);
            })->all();
    }

    /** The sheets of pictures offered to the planner (Weave chooses), labelled with their files in cell order. */
    public static function planSheets(string $conversationId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('create_documents')) return [];
        $out = [];
        foreach (DB::table('create_documents')->where('conversation_id', $conversationId)->where('status', 'ready')->orderBy('created_at')->get() as $d) {
            $c = self::choice($d);
            $ids = array_values(array_filter($c['offered'], fn ($id) => DB::table('create_attachments')->where('conversation_id', $conversationId)->where('asset_id', $id)->exists()));
            if (! $ids || ! $c['sheet'] || ! ($bytes = app(CreateStorage::class)->get($c['sheet']))) continue;
            $out[] = ['label' => 'Pictures from the user\'s document "'.$d->title.'", offered for you to use where they fit: cells left to right, then down, are assets '.implode(', ', array_map(fn ($id) => (string) $id, $c['offered'])).(count($ids) < count($c['offered']) ? ' (the user removed some; use only assets '.implode(', ', $ids).')' : ''),
                'media_type' => 'image/jpeg', 'data' => base64_encode($bytes)];
        }
        return $out;
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
