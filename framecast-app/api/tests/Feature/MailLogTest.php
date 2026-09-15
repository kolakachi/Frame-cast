<?php

namespace Tests\Feature;

use App\Models\MailLogEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class MailLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'mail_test',
            'database.connections.mail_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'services.resend.webhook_secret' => 'whsec_'.base64_encode('a-test-signing-key'),
        ]);
        DB::purge('mail_test');

        Schema::create('mail_log', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('email'); $t->string('mailable')->nullable(); $t->string('subject')->nullable();
            $t->string('provider_message_id')->nullable(); $t->string('message_id')->nullable();
            $t->string('status')->default('sent');
            $t->timestamp('sent_at')->nullable(); $t->timestamp('delivered_at')->nullable();
            $t->timestamp('first_opened_at')->nullable(); $t->timestamp('last_opened_at')->nullable();
            $t->unsignedInteger('open_count')->default(0);
            $t->timestamp('bounced_at')->nullable(); $t->timestamp('complained_at')->nullable();
            $t->text('failure_reason')->nullable(); $t->json('meta')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('name')->nullable(); $t->string('email')->nullable(); $t->timestamps();
        });
    }

    private function entry(array $o = []): MailLogEntry
    {
        return MailLogEntry::query()->create(array_merge([
            'email' => 'ada@acme.test', 'mailable' => 'MagicLinkMail',
            'subject' => 'Your WyvStudio sign-in link', 'status' => 'sent', 'sent_at' => now(),
        ], $o));
    }

    /** Signs a body the way Svix does, so the controller's check is exercised for real. */
    private function signedPost(array $payload, array $overrides = [])
    {
        $body = json_encode($payload);
        $id = 'msg_'.bin2hex(random_bytes(6));
        $ts = (string) time();
        $key = base64_decode(str_replace('whsec_', '', (string) config('services.resend.webhook_secret')));
        $sig = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $key, true));

        return $this->call('POST', '/api/v1/webhooks/resend', [], [], [], array_merge([
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => $ts,
            'HTTP_SVIX_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $overrides), $body);
    }

    private function event(string $type, array $data = []): array
    {
        return ['type' => $type, 'data' => array_merge([
            'email_id' => 'e_'.bin2hex(random_bytes(4)),
            'to' => ['ada@acme.test'],
            'subject' => 'Your WyvStudio sign-in link',
        ], $data)];
    }

    // ── the admin log endpoint ──────────────────────────────────────

    private function adminGet(array $params = [])
    {
        // The controller is exercised directly: the route sits behind admin
        // auth and IP allowlisting, neither of which is what these assert.
        $request = \Illuminate\Http\Request::create('/log', 'GET', $params);

        return (new \App\Http\Controllers\Api\V1\Admin\AdminMailController)
            ->log($request)->getData(true)['data'];
    }

    public function test_the_log_paginates_rather_than_returning_everything(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->entry(['email' => "user{$i}@acme.test", 'sent_at' => now()->subMinutes($i)]);
        }

        $first = $this->adminGet(['per_page' => 25, 'page' => 1]);
        $this->assertCount(25, $first['entries']);
        $this->assertSame(60, $first['pagination']['total']);
        $this->assertSame(3, $first['pagination']['last_page']);

        $last = $this->adminGet(['per_page' => 25, 'page' => 3]);
        $this->assertCount(10, $last['entries']);
        // Newest first, so page three holds the oldest.
        $this->assertNotSame($first['entries'][0]['id'], $last['entries'][0]['id']);
    }

    public function test_one_search_box_covers_recipient_subject_and_type(): void
    {
        $this->entry(['email' => 'ada@acme.test', 'subject' => 'Your sign-in link', 'mailable' => 'MagicLinkMail']);
        $this->entry(['email' => 'grace@other.test', 'subject' => 'Welcome to WyvStudio', 'mailable' => 'OnboardingDay0Welcome']);

        $this->assertCount(1, $this->adminGet(['search' => 'grace'])['entries']);
        $this->assertCount(1, $this->adminGet(['search' => 'sign-in'])['entries']);
        $this->assertCount(1, $this->adminGet(['search' => 'Day0'])['entries']);
        $this->assertCount(2, $this->adminGet(['search' => 'test'])['entries']);
    }

    public function test_filtering_by_status_and_type_narrows_the_list(): void
    {
        $this->entry(['status' => 'bounced', 'mailable' => 'MagicLinkMail']);
        $this->entry(['status' => 'delivered', 'mailable' => 'MagicLinkMail']);
        $this->entry(['status' => 'delivered', 'mailable' => 'AdminDirectMail']);

        $this->assertCount(1, $this->adminGet(['status' => 'bounced'])['entries']);
        $this->assertCount(2, $this->adminGet(['status' => 'delivered'])['entries']);
        $this->assertCount(2, $this->adminGet(['mailable' => 'MagicLinkMail'])['entries']);
        $this->assertCount(1, $this->adminGet(['status' => 'delivered', 'mailable' => 'MagicLinkMail'])['entries']);
    }

    public function test_opened_can_be_filtered_in_both_directions(): void
    {
        $this->entry(['first_opened_at' => now(), 'open_count' => 2, 'status' => 'opened']);
        $this->entry();
        $this->entry();

        $this->assertCount(1, $this->adminGet(['opened' => 'yes'])['entries']);
        $this->assertCount(2, $this->adminGet(['opened' => 'no'])['entries']);
        $this->assertSame(1, $this->adminGet()['opened_total']);
    }

    public function test_the_counts_describe_the_filtered_set_not_the_whole_table(): void
    {
        // A headline that disagrees with the rows under it is worse than none.
        $this->entry(['email' => 'ada@acme.test', 'status' => 'bounced']);
        $this->entry(['email' => 'ada@acme.test', 'status' => 'delivered']);
        $this->entry(['email' => 'someone@else.test', 'status' => 'delivered']);

        $filtered = $this->adminGet(['search' => 'ada']);
        $this->assertSame(1, $filtered['counts']['bounced'] ?? 0);
        $this->assertSame(1, $filtered['counts']['delivered'] ?? 0);
        $this->assertSame(2, $filtered['pagination']['total']);
    }

    public function test_the_type_filter_offers_only_types_we_have_actually_sent(): void
    {
        $this->entry(['mailable' => 'MagicLinkMail']);
        $this->entry(['mailable' => 'AdminDirectMail']);
        $this->entry(['mailable' => 'MagicLinkMail']);

        $this->assertSame(['AdminDirectMail', 'MagicLinkMail'], $this->adminGet()['mailables']);
    }

    public function test_an_unsigned_webhook_is_refused(): void
    {
        // Unverified delivery state is worse than none: anyone could mark a
        // bounced address as delivered, or the reverse.
        $this->entry();
        $this->postJson('/api/v1/webhooks/resend', $this->event('email.delivered'))
            ->assertStatus(401);
        $this->assertSame('sent', MailLogEntry::query()->firstOrFail()->status);
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $this->entry();
        $response = $this->signedPost($this->event('email.delivered'), [
            'HTTP_SVIX_SIGNATURE' => 'v1,'.base64_encode('not-the-right-mac'),
        ]);
        $response->assertStatus(401);
    }

    public function test_a_replayed_webhook_outside_the_window_is_refused(): void
    {
        $this->entry();
        $body = json_encode($this->event('email.delivered'));
        $id = 'msg_old';
        $ts = (string) (time() - 3600);
        $key = base64_decode(str_replace('whsec_', '', (string) config('services.resend.webhook_secret')));
        $this->call('POST', '/api/v1/webhooks/resend', [], [], [], [
            'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts,
            'HTTP_SVIX_SIGNATURE' => 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $key, true)),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
    }

    public function test_delivery_marks_the_matching_send(): void
    {
        $this->entry();
        $this->signedPost($this->event('email.delivered'))->assertOk();

        $e = MailLogEntry::query()->firstOrFail();
        $this->assertSame('delivered', $e->status);
        $this->assertNotNull($e->delivered_at);
        $this->assertNotNull($e->provider_message_id);
    }

    public function test_an_open_is_recorded_and_repeat_opens_only_bump_the_count(): void
    {
        // Opens repeat on every image load, forever. The first one is when it
        // was read; the rest are noise that still says the address is live.
        $this->entry();
        $id = 'e_fixed';
        $this->signedPost($this->event('email.opened', ['email_id' => $id]))->assertOk();
        $this->signedPost($this->event('email.opened', ['email_id' => $id]))->assertOk();
        $this->signedPost($this->event('email.opened', ['email_id' => $id]))->assertOk();

        $e = MailLogEntry::query()->firstOrFail();
        $this->assertSame('opened', $e->status);
        $this->assertSame(3, (int) $e->open_count);
        $this->assertNotNull($e->first_opened_at);
        $this->assertTrue($e->last_opened_at->greaterThanOrEqualTo($e->first_opened_at));
    }

    public function test_a_late_delivered_event_cannot_walk_an_opened_mail_backwards(): void
    {
        // Resend does not promise ordering, and reporting a read email as
        // merely delivered would quietly understate engagement.
        $this->entry();
        $id = 'e_order';
        $this->signedPost($this->event('email.opened', ['email_id' => $id]))->assertOk();
        $this->signedPost($this->event('email.delivered', ['email_id' => $id]))->assertOk();

        $e = MailLogEntry::query()->firstOrFail();
        $this->assertSame('opened', $e->status);
        $this->assertNotNull($e->delivered_at);   // still recorded, just not demoted
    }

    public function test_a_bounce_records_its_reason(): void
    {
        $this->entry();
        $this->signedPost($this->event('email.bounced', ['reason' => 'Mailbox does not exist']))->assertOk();

        $e = MailLogEntry::query()->firstOrFail();
        $this->assertSame('bounced', $e->status);
        $this->assertSame('Mailbox does not exist', $e->failure_reason);
        $this->assertNotNull($e->bounced_at);
    }

    public function test_events_attach_to_the_newest_send_to_that_address(): void
    {
        // Two links requested in a row: the second one's delivery must not be
        // stamped on the first.
        $old = $this->entry(['sent_at' => now()->subHour()]);
        $new = $this->entry(['sent_at' => now()]);

        $this->signedPost($this->event('email.delivered'))->assertOk();

        $this->assertSame('delivered', $new->fresh()->status);
        $this->assertSame('sent', $old->fresh()->status);
    }

    public function test_a_second_send_does_not_absorb_the_first_ones_events(): void
    {
        $first = $this->entry(['sent_at' => now()->subHour()]);
        $this->signedPost($this->event('email.delivered', ['email_id' => 'e_first']))->assertOk();
        $this->assertSame('delivered', $first->fresh()->status);

        $second = $this->entry(['sent_at' => now()]);
        $this->signedPost($this->event('email.opened', ['email_id' => 'e_first']))->assertOk();

        // Belongs to the first: it already owns that provider id.
        $this->assertSame('opened', $first->fresh()->status);
        $this->assertSame('sent', $second->fresh()->status);
    }

    public function test_an_event_for_someone_we_never_mailed_is_accepted_but_changes_nothing(): void
    {
        $this->entry();
        $this->signedPost($this->event('email.delivered', ['to' => ['stranger@nowhere.test']]))
            ->assertOk()
            ->assertJsonPath('data.matched', 0);
        $this->assertSame('sent', MailLogEntry::query()->firstOrFail()->status);
    }

    public function test_an_unknown_event_type_is_ignored_rather_than_erroring(): void
    {
        // Resend adds event types; an unrecognised one must not make them
        // retry a webhook forever.
        $this->signedPost($this->event('email.something_new'))
            ->assertOk()
            ->assertJsonPath('data.ignored', 'email.something_new');
    }

    public function test_with_no_secret_configured_everything_is_refused(): void
    {
        config(['services.resend.webhook_secret' => '']);
        $this->entry();
        $this->postJson('/api/v1/webhooks/resend', $this->event('email.delivered'))->assertStatus(401);
    }
}
