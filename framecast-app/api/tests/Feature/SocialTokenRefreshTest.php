<?php

namespace Tests\Feature;

use App\Jobs\RefreshSocialTokensJob;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformAdapter;
use App\Services\Publishing\PlatformAdapterFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class SocialTokenRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'social_test', 'database.connections.social_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('social_test');
        PlatformAdapterFactory::fake(null);

        Schema::create('social_accounts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable(); $t->string('platform');
            $t->string('platform_user_id')->nullable(); $t->string('platform_username')->nullable();
            $t->string('platform_display_name')->nullable(); $t->string('platform_avatar_url')->nullable();
            $t->text('access_token')->nullable(); $t->text('refresh_token')->nullable();
            $t->timestamp('token_expires_at')->nullable(); $t->string('status')->default('active');
            $t->text('scopes')->nullable(); $t->json('platform_meta')->nullable(); $t->timestamps();
        });
        // The model's created hook looks up the workspace owner to attribute
        // an analytics event.
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('email')->nullable(); $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        PlatformAdapterFactory::fake(null);
        parent::tearDown();
    }

    private function account(array $o = []): SocialAccount
    {
        return SocialAccount::query()->create(array_merge([
            'workspace_id' => 1, 'platform' => 'youtube',
            'access_token' => 'at', 'refresh_token' => 'rt',
            'token_expires_at' => now()->subHour(), 'status' => 'active',
        ], $o));
    }

    /** @param \Closure|null $onRefresh throw to simulate a revoked refresh token */
    private function fakeAdapter(?\Closure $onRefresh = null): void
    {
        $adapter = new class($onRefresh) implements PlatformAdapter
        {
            public function __construct(private $onRefresh) {}

            public function refreshToken(SocialAccount $account): void
            {
                if ($this->onRefresh) {
                    ($this->onRefresh)($account);
                }
                $account->forceFill(['token_expires_at' => now()->addHour()])->save();
            }

            // Unused here, but the interface requires them.
            public function getAuthUrl(string $state): string { return ''; }

            public function exchangeCode(string $code): array { return []; }

            public function publish(SocialAccount $account, \App\Models\ScheduledPost $post, string $videoPath): string { return ''; }

            public function platform(): string { return 'fake'; }
        };

        PlatformAdapterFactory::fake($adapter);
    }

    public function test_a_token_past_its_expiry_is_renewed(): void
    {
        $this->fakeAdapter();
        $a = $this->account(['token_expires_at' => now()->subWeeks(6)]);

        (new RefreshSocialTokensJob)->handle();

        $this->assertTrue($a->fresh()->token_expires_at->isFuture());
        $this->assertSame('active', $a->fresh()->status);
    }

    public function test_a_token_about_to_expire_is_renewed_before_it_lapses(): void
    {
        // Google's access tokens last an hour exactly; only catching expired
        // ones would always be racing the clock.
        $this->fakeAdapter();
        $a = $this->account(['token_expires_at' => now()->addMinutes(30)]);

        (new RefreshSocialTokensJob)->handle();

        $this->assertTrue($a->fresh()->token_expires_at->greaterThan(now()->addMinutes(45)));
    }

    public function test_a_healthy_token_is_left_alone(): void
    {
        $this->fakeAdapter();
        $a = $this->account(['token_expires_at' => now()->addDays(30)]);
        $before = $a->token_expires_at;

        (new RefreshSocialTokensJob)->handle();

        $this->assertEquals($before->timestamp, $a->fresh()->token_expires_at->timestamp);
    }

    public function test_a_revoked_refresh_token_marks_the_account_for_reconnection(): void
    {
        $this->fakeAdapter(fn () => throw new \RuntimeException('invalid_grant'));
        $a = $this->account();

        (new RefreshSocialTokensJob)->handle();

        $this->assertSame('expired', $a->fresh()->status);
    }

    public function test_an_account_already_needing_reconnection_is_not_retried_every_hour(): void
    {
        // Hammering a provider with a refresh token we know is dead, hourly,
        // forever, is how an app gets rate-limited.
        $calls = 0;
        $this->fakeAdapter(function () use (&$calls) { $calls++; throw new \RuntimeException('invalid_grant'); });
        $this->account(['status' => 'expired']);

        (new RefreshSocialTokensJob)->handle();

        $this->assertSame(0, $calls);
    }

    public function test_an_account_with_no_refresh_token_is_skipped(): void
    {
        $calls = 0;
        $this->fakeAdapter(function () use (&$calls) { $calls++; });
        $this->account(['refresh_token' => null]);

        (new RefreshSocialTokensJob)->handle();

        $this->assertSame(0, $calls);
    }

    public function test_one_dead_account_does_not_stop_the_others_refreshing(): void
    {
        $this->fakeAdapter(fn (SocialAccount $a) => $a->platform === 'tiktok'
            ? throw new \RuntimeException('invalid_grant') : null);
        $dead = $this->account(['platform' => 'tiktok']);
        $ok = $this->account(['platform' => 'youtube']);

        (new RefreshSocialTokensJob)->handle();

        $this->assertSame('expired', $dead->fresh()->status);
        $this->assertSame('active', $ok->fresh()->status);
        $this->assertTrue($ok->fresh()->token_expires_at->isFuture());
    }
}
