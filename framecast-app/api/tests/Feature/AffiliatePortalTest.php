<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Affiliate\PortalController;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateConversion;
use App\Models\AffiliateSession;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class AffiliatePortalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'portal_test', 'database.connections.portal_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('portal_test');

        Schema::create('affiliates', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->string('email')->nullable();
            $t->decimal('commission_percent', 5, 2)->default(20); $t->string('status')->default('active');
            $t->text('notes')->nullable(); $t->text('access_key')->nullable();
            $t->timestamp('last_login_at')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->string('token_hash');
            $t->timestamp('expires_at')->nullable(); $t->timestamp('last_seen_at')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_clicks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->timestamp('clicked_at')->nullable();
            $t->string('landing_path')->nullable(); $t->string('referer')->nullable();
            $t->string('visitor_hash')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_conversions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('customer_email')->nullable(); $t->string('order_id')->nullable(); $t->string('plan')->nullable();
            $t->decimal('order_amount', 10, 2)->default(0); $t->string('currency')->default('USD');
            $t->decimal('gross_amount', 10, 2)->default(0); $t->decimal('basis_amount', 10, 2)->default(0);
            $t->string('basis_method')->default('gross');
            $t->decimal('commission_percent', 5, 2); $t->decimal('commission_amount', 10, 2);
            $t->string('attribution_source')->default('workspace'); $t->string('payout_status')->default('unpaid');
            $t->unsignedBigInteger('payout_id')->nullable(); $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_payouts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->string('reference');
            $t->date('period_start')->nullable(); $t->date('period_end')->nullable();
            $t->unsignedInteger('sales_count')->default(0); $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('currency')->default('USD'); $t->string('status')->default('paid');
            $t->string('method')->nullable(); $t->text('note')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable(); $t->unsignedBigInteger('created_by_user_id')->nullable(); $t->timestamps();
        });
        RateLimiter::clear('aff-portal:marcus|127.0.0.1');
    }

    private string $key = '';

    private function affiliate(array $o = []): Affiliate
    {
        $this->key = Affiliate::generateAccessKey();

        return Affiliate::query()->create(array_merge([
            'code' => 'marcus', 'name' => 'Marcus', 'commission_percent' => 30,
            'status' => 'active', 'access_key' => $this->key,
        ], $o));
    }

    private function authed(string $token, string $method = 'GET', array $query = []): Request
    {
        $request = Request::create('/portal', $method, $query);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function signIn(Affiliate $a): string
    {
        $response = (new PortalController)->login(
            Request::create('/login', 'POST', ['code' => $a->code, 'key' => $this->key]),
        );
        $this->assertSame(200, $response->status());

        return $response->getData(true)['data']['token'];
    }

    public function test_the_public_referral_code_alone_does_not_open_the_dashboard(): void
    {
        // The whole reason the key exists: the code is in every URL they post.
        $a = $this->affiliate();
        $response = (new PortalController)->login(
            Request::create('/login', 'POST', ['code' => $a->code, 'key' => $a->code]),
        );
        $this->assertSame(401, $response->status());
        $this->assertSame(0, AffiliateSession::query()->count());
    }

    public function test_an_unknown_code_and_a_wrong_key_are_indistinguishable(): void
    {
        $this->affiliate();
        $controller = new PortalController;
        $wrongKey = $controller->login(Request::create('/login', 'POST', ['code' => 'marcus', 'key' => 'nope']));
        $noSuchCode = $controller->login(Request::create('/login', 'POST', ['code' => 'ghost', 'key' => 'nope']));

        $this->assertSame(401, $wrongKey->status());
        $this->assertSame(401, $noSuchCode->status());
        $this->assertSame(
            $wrongKey->getData(true)['error']['message'],
            $noSuchCode->getData(true)['error']['message'],
        );
    }

    public function test_repeated_guessing_is_throttled(): void
    {
        $this->affiliate();
        $controller = new PortalController;
        for ($i = 0; $i < 8; $i++) {
            $controller->login(Request::create('/login', 'POST', ['code' => 'marcus', 'key' => 'guess'.$i]));
        }
        $this->assertSame(429, $controller->login(
            Request::create('/login', 'POST', ['code' => 'marcus', 'key' => 'guess9']),
        )->status());
    }

    public function test_the_dashboard_shows_their_own_figures_and_never_another_affiliates(): void
    {
        $a = $this->affiliate();
        $mine = $this->key;
        $other = Affiliate::query()->create(['code' => 'dana', 'name' => 'Dana', 'commission_percent' => 25,
            'status' => 'active', 'access_key' => Affiliate::generateAccessKey()]);

        AffiliateClick::query()->create(['affiliate_id' => $a->getKey(), 'clicked_at' => now()]);
        AffiliateClick::query()->create(['affiliate_id' => $a->getKey(), 'clicked_at' => now()]);
        AffiliateClick::query()->create(['affiliate_id' => $other->getKey(), 'clicked_at' => now()]);
        AffiliateConversion::query()->create(['affiliate_id' => $a->getKey(), 'order_id' => 'o1',
            'order_amount' => 89, 'commission_percent' => 30, 'commission_amount' => 21.14]);
        AffiliateConversion::query()->create(['affiliate_id' => $other->getKey(), 'order_id' => 'o2',
            'order_amount' => 199, 'commission_percent' => 25, 'commission_amount' => 43.82]);

        $this->key = $mine;
        $totals = (new PortalController)->summary($this->authed($this->signIn($a)))->getData(true)['data']['totals'];

        $this->assertSame(2, $totals['clicks']);
        $this->assertSame(1, $totals['sales']);
        $this->assertEqualsWithDelta(21.14, $totals['earned'], 0.001);
        $this->assertEqualsWithDelta(50.0, $totals['conversion_rate'], 0.001);
    }

    public function test_the_daily_series_includes_days_with_no_activity(): void
    {
        // A chart missing its quiet days reads as an unbroken run of good ones.
        $a = $this->affiliate();
        AffiliateClick::query()->create(['affiliate_id' => $a->getKey(), 'clicked_at' => now()->subDays(3)]);
        // created_at is not fillable, so it has to be forced after the insert
        // or Eloquent stamps it with now() and the row lands on today.
        AffiliateConversion::query()->create(['affiliate_id' => $a->getKey(), 'order_id' => 'o1',
            'order_amount' => 89, 'commission_percent' => 30, 'commission_amount' => 21.14])
            ->forceFill(['created_at' => now()->subDays(3)])->save();

        $days = (new PortalController)->daily($this->authed($this->signIn($a), 'GET', [
            'from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString(),
        ]))->getData(true)['data']['days'];

        $this->assertCount(7, $days);
        $active = collect($days)->firstWhere('date', now()->subDays(3)->toDateString());
        $this->assertSame(1, $active['clicks']);
        $this->assertEqualsWithDelta(21.14, $active['commission'], 0.001);
        $this->assertSame(0, collect($days)->firstWhere('date', now()->toDateString())['clicks']);
    }

    public function test_a_voided_sale_is_not_counted_as_earnings(): void
    {
        $a = $this->affiliate();
        AffiliateConversion::query()->create(['affiliate_id' => $a->getKey(), 'order_id' => 'refunded',
            'order_amount' => 89, 'commission_percent' => 30, 'commission_amount' => 21.14, 'payout_status' => 'void']);

        $totals = (new PortalController)->summary($this->authed($this->signIn($a)))->getData(true)['data']['totals'];
        $this->assertSame(0, $totals['sales']);
        $this->assertEqualsWithDelta(0.0, $totals['earned'], 0.001);
    }

    public function test_no_customer_details_reach_the_affiliate(): void
    {
        // They are entitled to know a sale happened, not who made it.
        $a = $this->affiliate();
        AffiliateConversion::query()->create(['affiliate_id' => $a->getKey(), 'order_id' => 'o1',
            'customer_email' => 'buyer@private.example', 'order_amount' => 89,
            'commission_percent' => 30, 'commission_amount' => 21.14]);

        $token = $this->signIn($a);
        $controller = new PortalController;
        foreach ([$controller->summary($this->authed($token)), $controller->daily($this->authed($token)),
            $controller->payouts($this->authed($token))] as $response) {
            $this->assertStringNotContainsString('buyer@private.example', $response->getContent());
            $this->assertStringNotContainsString('customer_email', $response->getContent());
        }
    }

    public function test_pausing_an_affiliate_closes_their_dashboard_immediately(): void
    {
        $a = $this->affiliate();
        $token = $this->signIn($a);
        $this->assertSame(200, (new PortalController)->summary($this->authed($token))->status());

        $a->forceFill(['status' => 'paused'])->save();
        $this->assertSame(401, (new PortalController)->summary($this->authed($token))->status());
    }

    public function test_an_expired_or_revoked_session_stops_working(): void
    {
        $a = $this->affiliate();
        $token = $this->signIn($a);
        (new PortalController)->logout($this->authed($token, 'POST'));
        $this->assertSame(401, (new PortalController)->summary($this->authed($token))->status());

        $fresh = $this->signIn($a);
        AffiliateSession::query()->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(401, (new PortalController)->summary($this->authed($fresh))->status());
    }

    public function test_only_the_hash_of_a_session_token_is_stored(): void
    {
        $a = $this->affiliate();
        $token = $this->signIn($a);
        $this->assertSame(0, AffiliateSession::query()->where('token_hash', $token)->count());
        $this->assertSame(1, AffiliateSession::query()->where('token_hash', hash('sha256', $token))->count());
    }

    public function test_the_access_key_never_rides_along_in_a_portal_response(): void
    {
        $a = $this->affiliate();
        $key = $this->key;
        $response = (new PortalController)->summary($this->authed($this->signIn($a)));
        $this->assertStringNotContainsString($key, $response->getContent());
        $this->assertStringNotContainsString('access_key', $response->getContent());
    }
}
