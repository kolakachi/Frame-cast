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
