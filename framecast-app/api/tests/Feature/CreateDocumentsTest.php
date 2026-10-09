<?php

namespace Tests\Feature;

use App\Jobs\ReadCreateDocument;
use App\Models\{User, Workspace};
use App\Services\Create\{ConversationService, DocumentService, PlanService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/** Documents in Weave: PDF, Word and PowerPoint files read for a conversation, their pictures picked in a drawer. */
class CreateDocumentsTest extends TestCase
{
    use BuildsDeveloperSchema;
    private User $owner;
    private Workspace $workspace;
    private ConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'create_test', 'database.connections.create_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array', 'services.posthog.key' => '', 'create.enabled' => true, 'create.runtime_controls_enabled' => false, 'create.worker_ownership_required' => false, 'create.mode' => 'fixture', 'developer.operation_accounting' => true]);
        DB::purge('create_test');
        Bus::fake(); Http::preventStrayRequests(); Redis::shouldReceive('get')->andReturn(null);
        $this->buildDeveloperSchema();
        (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
        (require database_path('migrations/2026_09_28_120000_create_composition_conversations.php'))->up();
        (require database_path('migrations/2026_09_29_000000_create_composition_attempts.php'))->up();
        (require database_path('migrations/2026_09_29_120000_link_composition_outputs.php'))->up();
        (require database_path('migrations/2026_09_29_130000_create_composition_reconciliations.php'))->up();
        (require database_path('migrations/2026_09_29_180000_add_create_output_metadata.php'))->up();
        (require database_path('migrations/2026_09_29_190000_create_composition_deliveries.php'))->up();
        (require database_path('migrations/2026_09_30_120000_create_create_plans.php'))->up();
        (require database_path('migrations/2026_09_30_130000_add_create_provider_consent.php'))->up();
        (require database_path('migrations/2026_10_01_120000_create_create_plan_media.php'))->up();
        (require database_path('migrations/2026_10_01_130000_create_create_styles.php'))->up();
        (require database_path('migrations/2026_10_01_140000_create_create_pronunciations.php'))->up();
        (require database_path('migrations/2026_10_01_150000_create_create_style_notes.php'))->up();
        (require database_path('migrations/2026_10_03_120000_add_create_dispatch_journal.php'))->up();
        (require database_path('migrations/2026_10_03_150000_create_composition_trace_events.php'))->up();
        (require database_path('migrations/2026_10_03_160000_widen_create_plan_media_item_index.php'))->up();
        (require database_path('migrations/2026_10_06_160000_create_vendor_incidents.php'))->up();
        (require database_path('migrations/2026_10_06_220000_create_create_planning_jobs.php'))->up();
        (require database_path('migrations/2026_10_06_230000_create_create_stored_files.php'))->up();
        (require database_path('migrations/2026_10_07_000000_create_create_runtime_controls.php'))->up();
        (require database_path('migrations/2026_10_07_010000_create_create_worker_assignments.php'))->up();
        (require database_path('migrations/2026_10_07_120000_add_notes_to_create_attachments.php'))->up();
        (require database_path('migrations/2026_10_09_160000_create_create_documents.php'))->up();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->workspace = Workspace::create(['name' => 'Local', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $this->owner = User::create(['email' => 'local@example.test', 'name' => 'Local', 'role' => 'owner', 'status' => 'active']);
        $this->owner->forceFill(['workspace_id' => $this->workspace->id])->save();
        config(['create.workspaces' => [(int) $this->workspace->id], 'create.allowed_emails' => ['local@example.test', 'viewer@example.test']]);
        $this->conversations = app(ConversationService::class);
    }

    private function analysis(array $extra = []): array
    {
        $thumb = ['part' => 1, 'of' => 1, 'y0' => 0, 'y1' => 842, 'thumb' => 'AAAA'];
        return $extra + ['source' => 'pdf', 'page_count' => 3, 'chars' => 900, 'scanned_units' => 0, 'partial' => false, 'text_only' => false, 'pdf_base64' => null,
            'pages' => [
                ['number' => 1, 'kind' => 'text', 'chars' => 400, 'images' => 1, 'drawings' => 0, 'text' => 'Glow Serum, 30 ml. Vitamin C 15%.', 'parts' => [$thumb]],
                ['number' => 2, 'kind' => 'text', 'chars' => 300, 'images' => 0, 'drawings' => 40, 'text' => 'Results after 7 days: 92% saw a visible glow.', 'parts' => [$thumb, ['part' => 2, 'of' => 2] + $thumb]],
                ['number' => 3, 'kind' => 'text', 'chars' => 200, 'images' => 0, 'drawings' => 0, 'text' => 'Launch price $29 until December 31.', 'parts' => [$thumb]],
            ],
            'pictures' => [['id' => 'x5', 'page' => 1, 'width' => 900, 'height' => 1200, 'parts' => [$thumb]]]];
    }

    private function jpeg(): string
    {
        // A one-pixel JPEG (the PHP image library is not in the test image).
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    private function added(string $name = 'brochure.pdf', string $body = "%PDF-1.4\n%fake"): object
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $d = app(DocumentService::class)->add($this->owner, $c->id, UploadedFile::fake()->createWithContent($name, $body));
        return $d;
    }

    public function test_a_document_is_stored_and_read_in_the_background(): void
    {
        $d = $this->added();
        $this->assertSame('reading', $d->status);
        $this->assertSame('pdf', $d->source);
        Bus::assertDispatched(ReadCreateDocument::class, fn ($j) => $j->documentId === $d->id);
        $this->assertNotNull(app(\App\Services\Create\CreateStorage::class)->get($d->path));

        Http::fake(['extract:8000/extract/document' => Http::response($this->analysis())]);
        app(DocumentService::class)->readNow($d->id);
        $row = DB::table('create_documents')->where('id', $d->id)->first();
        $this->assertSame('ready', $row->status);
        $this->assertSame(3, (int) $row->page_count);
        $this->assertStringContainsString("[page 2]\nResults after 7 days", $row->text);
        $this->assertStringNotContainsString('Glow Serum', (string) $row->analysis_json, 'the words are kept once, in text');
        $brief = DocumentService::brief($row);
        $this->assertSame([1, false, false], [$brief['pictures'], $brief['text_only'], $brief['chosen']]);
        $full = DocumentService::full($row);
        $this->assertTrue($full['pages'][1]['graphic'], 'a page of drawn shapes is offered as a chart');
        $this->assertCount(2, $full['pages'][1]['parts']);
    }

    public function test_only_pdf_word_and_powerpoint_files_are_taken(): void
    {
        foreach ([['notes.txt', 'hello'], ['deck.pptx', '%PDF-not a zip'], ['brochure.pdf', 'PK not a pdf']] as [$name, $body]) {
            try { $this->added($name, $body); $this->fail($name.' was accepted'); }
            catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        }
        $c = $this->conversations->create($this->owner, []);
        for ($i = 0; $i < DocumentService::PER_CONVERSATION; $i++) app(DocumentService::class)->add($this->owner, $c->id, UploadedFile::fake()->createWithContent('d'.$i.'.pdf', '%PDF-1.4'));
        try { app(DocumentService::class)->add($this->owner, $c->id, UploadedFile::fake()->createWithContent('more.pdf', '%PDF-1.4')); $this->fail('a sixth document was accepted'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }

    public function test_a_converted_word_file_is_kept_as_its_pdf_and_a_refusal_is_shown(): void
    {
        $d = $this->added('notes.docx', "PK\x03\x04word");
        $analysis = $this->analysis(['source' => 'docx', 'pdf_base64' => base64_encode('%PDF-converted'), 'text_only' => true, 'pictures' => []]);
        Http::fake(['extract:8000/extract/document' => fn ($request) => str_contains($request->body(), 'locked.pdf')
            ? Http::response(['detail' => 'That PDF is password-protected. Remove the password and try again.'], 422) : Http::response($analysis)]);
        app(DocumentService::class)->readNow($d->id);
        $row = DB::table('create_documents')->where('id', $d->id)->first();
        $this->assertStringEndsWith('.pdf', $row->path);
        $this->assertSame('%PDF-converted', app(\App\Services\Create\CreateStorage::class)->get($row->path));
        $this->assertTrue(DocumentService::brief($row)['text_only']);

        $locked = $this->added('locked.pdf');
        app(DocumentService::class)->readNow($locked->id);
        $row = DB::table('create_documents')->where('id', $locked->id)->first();
        $this->assertSame(['failed', 'That PDF is password-protected. Remove the password and try again.'], [$row->status, $row->error]);
    }

    public function test_facts_are_read_and_billed_with_the_next_plan(): void
    {
        $d = $this->added();
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'test-key']);
        Http::fake([
            'extract:8000/extract/document' => Http::response($this->analysis()),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"summary": "A brochure for Glow Serum.", "facts": [{"text": "Launch price $29 until December 31", "page": 3}, {"text": "", "page": 1}]}']],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]),
        ]);
        app(DocumentService::class)->readNow($d->id);
        $brief = DocumentService::brief(DB::table('create_documents')->where('id', $d->id)->first());
        $this->assertSame('A brochure for Glow Serum.', $brief['summary']);
        $this->assertSame([['text' => 'Launch price $29 until December 31', 'page' => 3]], $brief['facts']);
        $this->assertGreaterThan(0, \App\Services\Create\PlanningCosts::take($d->conversation_id)['document'] ?? 0);

        $plan = DocumentService::forPlan($d->conversation_id);
        $this->assertSame(['Launch price $29 until December 31 (page 3)'], $plan[0]['facts']);
        $this->assertStringContainsString('92% saw a visible glow', $plan[0]['text']);
        // The document's words count as the user's own, so lines taken from it are not marked as new wording.
        $this->assertSame([], PlanService::newWording(['Visible glow in 7 days', 'Launch price $29'], ['documents' => $plan]));
        $this->assertSame(['Clinically proven'], PlanService::newWording(['Clinically proven'], ['documents' => $plan]));
    }

    public function test_ticked_pictures_and_pages_become_source_pictures_noted_with_their_page(): void
    {
        $d = $this->added();
        Http::fake(['extract:8000/extract/document' => Http::response($this->analysis())]);
        app(DocumentService::class)->readNow($d->id);
        $jpeg = base64_encode($this->jpeg());
        $asked = null;
        Http::fake(['extract:8000/extract/document/render' => function ($request) use (&$asked, $jpeg) {
            foreach ($request->data() as $part) if (($part['name'] ?? '') === 'items') $asked = json_decode($part['contents'], true);
            return Http::response(['images' => [
                ['key' => 'picture-x5-1', 'mime_type' => 'image/jpeg', 'width' => 40, 'height' => 60, 'base64' => $jpeg],
                ['key' => 'page-2-2', 'mime_type' => 'image/jpeg', 'width' => 40, 'height' => 60, 'base64' => $jpeg]]]);
        }]);
        $version = (int) DB::table('create_conversations')->where('id', $d->conversation_id)->value('version');
        app(DocumentService::class)->useParts($this->owner, $d->conversation_id, $d->id, ['pages', 'pictures'], [
            ['kind' => 'picture', 'id' => 'x5', 'part' => 1], ['kind' => 'page', 'number' => 2, 'part' => 2],
            ['kind' => 'page', 'number' => 9, 'part' => 1], ['kind' => 'picture', 'id' => 'x5', 'part' => 1]], $version);
        $this->assertSame([['kind' => 'picture', 'id' => 'x5', 'part' => 1], ['kind' => 'page', 'number' => 2, 'part' => 2]], $asked, 'unknown and repeated picks are dropped');
        $rows = DB::table('create_attachments')->where('conversation_id', $d->conversation_id)->orderBy('id')->get();
        $this->assertSame(['source', 'source'], $rows->pluck('purpose')->all());
        $notes = $rows->map(fn ($r) => json_decode($r->notes_json, true))->all();
        $this->assertSame(['document_picture', 'document_page'], array_column($notes, 'kind'));
        $this->assertSame('From brochure.pdf, picture, page 1', $notes[0]['use']);
        $this->assertSame('From brochure.pdf, page 2 (part 2 of 2)', $notes[1]['use']);
        $brief = DocumentService::brief(DB::table('create_documents')->where('id', $d->id)->first());
        $this->assertSame([true, ['pages', 'pictures'], 2], [$brief['chosen'], $brief['modes'], $brief['picked']]);
        $this->assertSame(['pages', 'pictures'], DocumentService::forPlan($d->conversation_id)[0]['use']);
    }

    public function test_ticks_count_only_for_the_ways_chosen_and_speaker_notes_become_the_script(): void
    {
        $d = $this->added('pitch.pptx', "PK\x03\x04deck");
        $analysis = $this->analysis(['source' => 'pptx', 'pdf_base64' => base64_encode('%PDF-deck'), 'notes' => [['slide' => 1, 'text' => 'Most agencies lose a day a week to reporting.'], ['slide' => 3, 'text' => 'Three months for the price of two.']]]);
        $plainAnalysis = $this->analysis();
        Http::fake(['extract:8000/extract/document' => fn ($request) => Http::response(str_contains($request->body(), 'pitch.pptx') ? $analysis : $plainAnalysis)]);
        app(DocumentService::class)->readNow($d->id);
        $jpeg = base64_encode($this->jpeg());
        $asked = null;
        Http::fake(['extract:8000/extract/document/render' => function ($request) use (&$asked, $jpeg) {
            foreach ($request->data() as $part) if (($part['name'] ?? '') === 'items') $asked = json_decode($part['contents'], true);
            return Http::response(['images' => [['key' => 'page-1-1', 'mime_type' => 'image/jpeg', 'width' => 40, 'height' => 60, 'base64' => $jpeg]]]);
        }]);
        $version = fn () => (int) DB::table('create_conversations')->where('id', $d->conversation_id)->value('version');
        // A picture ticked while "Use its pictures" is off is not used.
        app(DocumentService::class)->useParts($this->owner, $d->conversation_id, $d->id, ['pages', 'notes'], [['kind' => 'page', 'number' => 1, 'part' => 1], ['kind' => 'picture', 'id' => 'x5', 'part' => 1]], $version());
        $this->assertSame([['kind' => 'page', 'number' => 1, 'part' => 1]], $asked);
        $note = json_decode((string) DB::table('create_attachments')->where('conversation_id', $d->conversation_id)->value('notes_json'), true);
        $this->assertSame('From pitch.pptx, slide 1', $note['use']);
        $plan = DocumentService::forPlan($d->conversation_id)[0];
        $this->assertSame(['slides', ['pages', 'notes']], [$plan['kind'], $plan['use']]);
        $this->assertSame([['slide' => 1, 'notes' => 'Most agencies lose a day a week to reporting.'], ['slide' => 3, 'notes' => 'Three months for the price of two.']], $plan['speaker_notes']);

        // Words only clears the rest; a document must be given a way to be used.
        app(DocumentService::class)->useParts($this->owner, $d->conversation_id, $d->id, ['words', 'pages'], [['kind' => 'page', 'number' => 2, 'part' => 1]], $version());
        $this->assertSame(['words'], DocumentService::forPlan($d->conversation_id)[0]['use']);
        $this->assertArrayNotHasKey('speaker_notes', DocumentService::forPlan($d->conversation_id)[0]);
        try { app(DocumentService::class)->useParts($this->owner, $d->conversation_id, $d->id, ['notes'], [], $version()); $this->addToAssertionCount(1); }
        catch (HttpException $e) { $this->fail('notes alone is a choice for a deck with notes'); }
        $plain = $this->added();
        app(DocumentService::class)->readNow($plain->id);
        try { app(DocumentService::class)->useParts($this->owner, $plain->conversation_id, $plain->id, ['notes'], [], (int) DB::table('create_conversations')->where('id', $plain->conversation_id)->value('version')); $this->fail('notes was taken for a file without notes'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }

    public function test_letting_weave_choose_offers_the_larger_pictures_on_a_sheet_the_planner_sees(): void
    {
        $d = $this->added();
        $thumb = ['part' => 1, 'of' => 1, 'y0' => 0, 'y1' => 100, 'thumb' => 'AAAA'];
        $analysis = $this->analysis(['pictures' => [
            ['id' => 'x5', 'page' => 1, 'width' => 900, 'height' => 1200, 'parts' => [$thumb]],
            ['id' => 'x6', 'page' => 2, 'width' => 120, 'height' => 90, 'parts' => [$thumb]],
            ['id' => 'x7', 'page' => 3, 'width' => 800, 'height' => 3000, 'parts' => [$thumb, ['part' => 2] + $thumb]],
        ]]);
        Http::fake(['extract:8000/extract/document' => Http::response($analysis)]);
        app(DocumentService::class)->readNow($d->id);
        $jpeg = base64_encode($this->jpeg());
        $asked = null;
        Http::fake(['extract:8000/extract/document/render' => function ($request) use (&$asked, $jpeg) {
            foreach ($request->data() as $part) if (($part['name'] ?? '') === 'items') $asked = json_decode($part['contents'], true);
            return Http::response(['images' => array_map(fn ($i) => ['key' => $i['kind'].'-'.$i['id'].'-'.$i['part'], 'mime_type' => 'image/jpeg', 'width' => 1, 'height' => 1, 'base64' => $jpeg], $asked)]);
        }]);
        $version = (int) DB::table('create_conversations')->where('id', $d->conversation_id)->value('version');
        // A ticked picture is ignored: Weave chooses them.
        app(DocumentService::class)->useParts($this->owner, $d->conversation_id, $d->id, ['auto', 'pictures'], [['kind' => 'picture', 'id' => 'x6', 'part' => 1]], $version);
        $this->assertSame([['kind' => 'picture', 'id' => 'x7', 'part' => 1], ['kind' => 'picture', 'id' => 'x7', 'part' => 2], ['kind' => 'picture', 'id' => 'x5', 'part' => 1]], $asked, 'larger first, every part of a tall one, the tiny one left out');
        $notes = DB::table('create_attachments')->where('conversation_id', $d->conversation_id)->orderBy('id')->pluck('notes_json')->map(fn ($n) => json_decode($n, true));
        $this->assertCount(3, $notes);
        $this->assertTrue($notes->every(fn ($n) => ($n['optional'] ?? false) === true && $n['kind'] === 'document_picture'));
        $this->assertStringEndsWith('(use it where it fits)', $notes[2]['use']);
        $brief = DocumentService::brief(DB::table('create_documents')->where('id', $d->id)->first());
        $this->assertSame([['auto'], 3, 0], [$brief['modes'], $brief['offered'], $brief['picked']]);
        $sheets = DocumentService::planSheets($d->conversation_id);
        $this->assertCount(1, $sheets, 'the planner sees the offered pictures on one sheet');
        $this->assertStringContainsString('offered for you to use where they fit', $sheets[0]['label']);
        $this->assertSame('/9j/', substr($sheets[0]['data'], 0, 4));
    }

        public function test_using_the_words_only_and_another_workspace_cannot_see_it(): void
    {
        $d = $this->added();
        Http::fake(['extract:8000/extract/document' => Http::response($this->analysis())]);
        app(DocumentService::class)->readNow($d->id);
        $version = (int) DB::table('create_conversations')->where('id', $d->conversation_id)->value('version');
        app(DocumentService::class)->useParts($this->owner, $d->conversation_id, $d->id, ['words'], [], $version);
        $this->assertSame(0, DB::table('create_attachments')->where('conversation_id', $d->conversation_id)->count());
        $this->assertTrue(DocumentService::brief(DB::table('create_documents')->where('id', $d->id)->first())['chosen']);

        $other = Workspace::create(['name' => 'Other', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $stranger = User::create(['email' => 'viewer@example.test', 'name' => 'V', 'role' => 'owner', 'status' => 'active']);
        $stranger->forceFill(['workspace_id' => $other->id])->save();
        config(['create.workspaces' => [(int) $this->workspace->id, (int) $other->id]]);
        try { app(DocumentService::class)->document($stranger, $d->conversation_id, $d->id); $this->fail('another workspace read the document'); }
        catch (\Illuminate\Database\RecordNotFoundException) { $this->addToAssertionCount(1); }

        app(DocumentService::class)->remove($this->owner, $d->conversation_id, $d->id);
        $this->assertSame(0, DB::table('create_documents')->count());
    }
}
