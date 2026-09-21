<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Models\AuthSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AuthSessionService;
use App\Services\Auth\JwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'signup_test', 'database.connections.signup_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'billing.require_plan_on_register' => true]);
        DB::purge('signup_test');
        Cache::flush(); Mail::fake(); Bus::fake();
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'plan_tier', 'status', 'intended_plan'] as $c) $t->string($c)->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->unsignedBigInteger('referred_by_workspace_id')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id');
            foreach (['name', 'password_hash', 'timezone', 'role', 'status'] as $c) $t->string($c)->nullable();
            $t->string('email')->unique(); $t->integer('onboarding_step')->nullable();
            $t->timestamp('onboarding_last_sent_at')->nullable(); $t->timestamps();
        });
        $reward = $this->createMock(\App\Services\RewardService::class);
        $reward->method('referrerIdForCode')->willReturn(null);
        $this->app->instance(\App\Services\RewardService::class, $reward);
        $access = $this->createMock(\App\Services\Agency\WorkspaceAccess::class);
        $access->method('activate')->willReturn(true);
        $this->app->instance(\App\Services\Agency\WorkspaceAccess::class, $access);
    }

    private function request(): Request
    {
        // Stub only input validation to keep these controller-flow tests offline (email DNS).
        $request = \Mockery::mock(Request::class)->makePartial();
        $request->shouldReceive('validate')->once()->andReturn([
            'name' => 'Buyer', 'email' => 'buyer@example.com', 'plan' => 'ugc_pass',
        ]);
        $request->shouldReceive('ip')->andReturn('127.0.0.44');
        return $request;
    }

    public function test_new_registration_returns_session_and_persists_pass_without_magic_mail(): void
    {
        $sessions = $this->createMock(AuthSessionService::class);
        $sessions->expects($this->once())->method('create')->willReturnCallback(fn ($user) => [
            (new AuthSession)->forceFill(['active_workspace_id' => $user->workspace_id]), 'refresh-test',
        ]);
        $jwt = $this->createMock(JwtService::class);
        $jwt->method('issue')->willReturn('access-test');
        $response = (new AuthController($sessions, $jwt))->register($this->request());
        $this->assertSame(200, $response->status());
        $this->assertSame('access-test', $response->getData(true)['data']['access_token']);
        $this->assertSame('ugc_pass', Workspace::first()->intended_plan);
        $this->assertSame('free', Workspace::first()->plan_tier);
        Mail::assertNothingSent();
    }

    public function test_existing_email_cannot_receive_a_registration_session(): void
    {
        User::create(['workspace_id' => 1, 'email' => 'buyer@example.com', 'name' => 'Existing']);
        $sessions = $this->createMock(AuthSessionService::class);
        $sessions->expects($this->never())->method('create');
        $response = (new AuthController($sessions, $this->createMock(JwtService::class)))->register($this->request());
        $this->assertSame(409, $response->status());
        $this->assertSame('account_exists', $response->getData(true)['error']['code']);
        $this->assertSame(1, User::count());
        Mail::assertNothingSent();
    }

    public function test_transaction_rechecks_existing_email_before_issuing_session(): void
    {
        User::create(['workspace_id' => 1, 'email' => 'buyer@example.com', 'name' => 'Existing']);
        $controller = new AuthController($this->createMock(AuthSessionService::class), $this->createMock(JwtService::class));
        $method = new \ReflectionMethod($controller, 'findOrCreateUser');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($controller, ['email' => 'buyer@example.com', 'password' => 'attacker-password'], true);
    }
    public function test_existing_customer_magic_link_preserves_selected_plan_without_issuing_session(): void
    {
        Schema::create('magic_link_tokens', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('email'); $t->string('token_hash');
            $t->timestamp('expires_at'); $t->timestamp('used_at')->nullable(); $t->timestamp('created_at');
        });
        $workspace = Workspace::create(['name' => 'Buyer', 'plan_tier' => 'free']);
        User::create(['workspace_id' => $workspace->id, 'email' => 'buyer@example.com', 'name' => 'Existing']);
        $sessions = $this->createMock(AuthSessionService::class);
        $sessions->expects($this->never())->method('create');
        $response = (new AuthController($sessions, $this->createMock(JwtService::class)))->magicLink($this->request());
        $this->assertTrue($response->getData(true)['data']['sent']);
        $this->assertSame('ugc_pass', $workspace->fresh()->intended_plan);
        Mail::assertSent(\App\Mail\MagicLinkMail::class);
    }

    public function test_magic_link_request_cannot_set_an_existing_accounts_password(): void
    {
        $workspace = Workspace::create(['name' => 'Buyer', 'plan_tier' => 'free']);
        $user = User::create(['workspace_id' => $workspace->id, 'email' => 'buyer@example.com', 'name' => 'Existing']);
        $controller = new AuthController($this->createMock(AuthSessionService::class), $this->createMock(JwtService::class));
        $method = new \ReflectionMethod($controller, 'findOrCreateUser');
        $method->invoke($controller, ['email' => 'buyer@example.com', 'password' => 'attacker-password'], false);
        $this->assertNull($user->fresh()->password_hash);
    }

}
