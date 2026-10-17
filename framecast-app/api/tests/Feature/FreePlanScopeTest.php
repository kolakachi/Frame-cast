<?php

namespace Tests\Feature;

use App\Http\Middleware\FreePlanScope;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/** A free account makes one free ad and can upgrade; nothing else (owner, 2026-10-17). */
class FreePlanScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'free_scope', 'database.connections.free_scope' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('free_scope');
        Schema::create('workspaces', function (Blueprint $t) { $t->id(); $t->string('plan_tier')->nullable(); $t->timestamps(); });
        DB::table('workspaces')->insert([['id' => 1, 'plan_tier' => 'free'], ['id' => 2, 'plan_tier' => 'creator']]);
    }

    private function answer(string $method, string $path, int $workspace, string $role = 'owner'): int
    {
        $user = (new User)->forceFill(['id' => 9, 'workspace_id' => $workspace, 'role' => $role]);
        $request = Request::create('/api/'.$path, $method);
        $request->setUserResolver(fn () => $user);
        return (new FreePlanScope)->handle($request, fn () => response('ok'))->getStatusCode();
    }

    public function test_a_free_account_is_refused_making_anything_outside_its_free_ad_and_reads_everything(): void
    {
        foreach (['v1/projects', 'v1/ugc/chats', 'v1/characters', 'v1/voice-profiles/clone', 'v1/scheduled-posts', 'v1/daily-streak/claim', 'v1/assets'] as $path) {
            $this->assertSame(402, $this->answer('POST', $path, 1), $path);
            $this->assertSame(200, $this->answer('POST', $path, 2), 'a paid plan: '.$path);
            $this->assertSame(200, $this->answer('POST', $path, 1, 'super_admin'), 'staff: '.$path);
        }
        foreach (['v1/create/templates/b01/use', 'v1/create/conversations/x/plans', 'v1/billing/checkout', 'v1/auth/logout', 'v1/onboarding', 'v1/feedback', 'v1/workspaces/1'] as $path) {
            $this->assertSame(200, $this->answer('POST', $path, 1), 'open to a free account: '.$path);
        }
        $this->assertSame(200, $this->answer('GET', 'v1/projects', 1), 'past videos stay readable');
        $this->assertSame(402, $this->answer('DELETE', 'v1/projects/5', 1), 'read-only: nothing deleted or changed');
        // The API and connector: only Create ("weave") makes anything for a free account.
        $this->assertSame(200, $this->answer('POST', 'developer/v1/weave/videos', 1));
        $this->assertSame(402, $this->answer('POST', 'developer/v1/ugc/videos', 1));
        $this->assertSame(200, $this->answer('GET', 'developer/v1/capabilities', 1));
    }
}
