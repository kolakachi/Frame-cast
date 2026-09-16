<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWithJwt;
use App\Models\AuthSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\JwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The read-only half of client access.
 *
 * A client viewer signs in to a workspace whose credits belong to somebody
 * else, so "what can they do" is the whole security story of the feature. The
 * guard is fail-closed on purpose; these tests exist to notice the day it
 * stops being.
 *
 * Deliberately uses a fresh in-memory DB, never the configured application database.
 */
class ClientViewerAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'cva_test', 'database.connections.cva_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('cva_test');

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('parent_workspace_id')->nullable();
            $t->string('client_label')->nullable(); $t->string('name')->nullable();
            $t->string('plan_tier')->nullable(); $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('auth_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('refresh_token_hash')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('email')->nullable(); $t->string('name')->nullable();
            $t->string('role')->nullable(); $t->string('status')->nullable();
            $t->timestamp('last_seen_at')->nullable(); $t->timestamps();
        });
    }

    /** @return array{0: User, 1: Workspace} */
    private function viewer(string $role = User::ROLE_CLIENT_VIEWER): array
    {
        $agency = Workspace::query()->create(['name' => 'Northstar', 'plan_tier' => 'agency', 'status' => 'active']);
        $client = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => 'agency', 'status' => 'active']);
        $client->forceFill(['parent_workspace_id' => $agency->getKey()])->save();

        $u = User::query()->create(['email' => 'ops@acme.test', 'name' => 'Ops', 'role' => $role, 'status' => 'active']);
        $u->forceFill(['workspace_id' => $client->getKey()])->save();

        return [$u->fresh(), $client->fresh()];
    }

    private function guard(User $user, string $method, string $uri, ?int $workspaceId = null): int
    {
        $workspace = Workspace::query()->findOrFail($workspaceId ?? (int) $user->workspace_id);
        $session = AuthSession::query()->create(['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey()]);
        $token = app(JwtService::class)->issue($user, $workspace, $session);

        $request = Request::create('/'.ltrim($uri, '/'), $method);
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = app(AuthenticateWithJwt::class)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
        );

        return $response->getStatusCode();
    }

    public function test_a_client_viewer_can_read_their_own_workspace(): void
    {
        [$u] = $this->viewer();
        $this->assertSame(200, $this->guard($u, 'GET', 'api/v1/projects'));
    }

    public function test_a_client_viewer_cannot_create_anything(): void
    {
        // The default answer for any method that is not safe.
        [$u] = $this->viewer();
        $this->assertSame(403, $this->guard($u, 'POST', 'api/v1/projects'));
        $this->assertSame(403, $this->guard($u, 'PATCH', 'api/v1/projects/1'));
        $this->assertSame(403, $this->guard($u, 'DELETE', 'api/v1/projects/1'));
    }

    public function test_a_client_viewer_may_still_approve_and_sign_out(): void
    {
        [$u] = $this->viewer();
        $this->assertSame(200, $this->guard($u, 'POST', 'api/v1/approvals/5/decide'));
        $this->assertSame(200, $this->guard($u, 'POST', 'api/v1/auth/logout'));
        $this->assertSame(200, $this->guard($u, 'POST', 'api/v1/feedback'));
    }

    public function test_a_client_viewer_can_still_manage_their_own_account(): void
    {
        // Their name, timezone and preferences are theirs, not the agency's.
        // Refusing PATCH /me trapped invited clients on the onboarding screen:
        // "skip" writes preferences.onboarded and got a 403 with no way on.
        [$u] = $this->viewer();
        $this->assertSame(200, $this->guard($u, 'PATCH', 'api/v1/me'));
        $this->assertSame(200, $this->guard($u, 'GET', 'api/v1/me'));
    }

    public function test_a_client_viewer_cannot_see_the_agency_roster(): void
    {
        // homeWorkspace() resolves a child to its parent, so without the block
        // this GET would hand a client the agency's whole client list.
        [$u] = $this->viewer();
        $this->assertSame(403, $this->guard($u, 'GET', 'api/v1/workspaces/clients'));
        $this->assertSame(403, $this->guard($u, 'GET', 'api/v1/workspaces/clients/usage'));
    }

    public function test_a_client_viewer_cannot_switch_into_another_workspace(): void
    {
        [$u, $client] = $this->viewer();
        $sibling = Workspace::query()->create(['name' => 'Rival', 'status' => 'active']);
        $sibling->forceFill(['parent_workspace_id' => $client->parent_workspace_id])->save();

        $this->assertSame(403, $this->guard($u, 'POST', 'api/v1/workspaces/switch/'.$sibling->getKey()));
        // Even holding a token that names the sibling directly.
        $this->assertSame(401, $this->guard($u, 'GET', 'api/v1/projects', (int) $sibling->getKey()));
    }

    public function test_an_agency_owner_is_untouched_by_the_guard(): void
    {
        $agency = Workspace::query()->create(['name' => 'Northstar', 'plan_tier' => 'agency', 'status' => 'active']);
        $owner = User::query()->create(['email' => 'a@b.test', 'name' => 'A', 'role' => 'owner', 'status' => 'active']);
        $owner->forceFill(['workspace_id' => $agency->getKey()])->save();

        $this->assertSame(200, $this->guard($owner->fresh(), 'POST', 'api/v1/projects'));
        $this->assertSame(200, $this->guard($owner->fresh(), 'GET', 'api/v1/workspaces/clients'));
    }

    // ── Editor and admin seats ──────────────────────────────────────────

    public function test_an_editor_can_make_and_change_videos(): void
    {
        // The inverse of the viewer rule: everything that is not the agency's
        // own business. Enumerating every endpoint that makes a video would be
        // a list nobody keeps correct, and the first one forgotten is a paid-
        // for feature the customer cannot use.
        [$u] = $this->viewer(User::ROLE_CLIENT_EDITOR);

        $this->assertSame(200, $this->guard($u, 'POST', 'api/v1/projects'));
        $this->assertSame(200, $this->guard($u, 'PATCH', 'api/v1/scenes/9'));
        $this->assertSame(200, $this->guard($u, 'POST', 'api/v1/exports'));
        $this->assertSame(200, $this->guard($u, 'DELETE', 'api/v1/projects/3'));
    }

    public function test_an_admin_can_too(): void
    {
        [$u] = $this->viewer(User::ROLE_CLIENT_ADMIN);
        $this->assertSame(200, $this->guard($u, 'POST', 'api/v1/projects'));
    }

    public function test_no_seat_reaches_the_agency_however_senior(): void
    {
        // The boundary that does not move with the seat. Admin is admin OF a
        // client workspace, not of the agency that owns it.
        foreach ([User::ROLE_CLIENT_VIEWER, User::ROLE_CLIENT_EDITOR, User::ROLE_CLIENT_ADMIN] as $role) {
            [$u] = $this->viewer($role);

            $this->assertSame(403, $this->guard($u, 'GET', 'api/v1/workspaces/clients'), $role);
            $this->assertSame(403, $this->guard($u, 'GET', 'api/v1/workspaces/clients/usage'), $role);
            $this->assertSame(403, $this->guard($u, 'GET', 'api/v1/admin/workspaces'), $role);
            $this->assertSame(403, $this->guard($u, 'POST', 'api/v1/billing/checkout'), $role);
        }
    }

    public function test_every_seat_keeps_its_own_account(): void
    {
        foreach ([User::ROLE_CLIENT_VIEWER, User::ROLE_CLIENT_EDITOR, User::ROLE_CLIENT_ADMIN] as $role) {
            [$u] = $this->viewer($role);
            $this->assertSame(200, $this->guard($u, 'PATCH', 'api/v1/me'), $role);
        }
    }

    public function test_a_viewer_is_still_refused_what_an_editor_may_do(): void
    {
        [$u] = $this->viewer(User::ROLE_CLIENT_VIEWER);
        $this->assertSame(403, $this->guard($u, 'POST', 'api/v1/projects'));
    }

}
