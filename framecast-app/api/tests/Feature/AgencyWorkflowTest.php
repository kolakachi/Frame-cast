<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Workspace\ClientHubController;
use App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController;
use App\Http\Middleware\AuthenticateWithJwt;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agency\WorkspaceAccess;
use App\Services\Auth\AuthSessionService;
use App\Services\Auth\JwtService;
use App\Services\CreditService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Uses the existing isolated agency fixture, never a configured customer database. */
class AgencyWorkflowTest extends ClientWorkspaceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_tokens.jwt_secret' => 'isolated-agency-test-key', 'auth_tokens.access_ttl_minutes' => 15, 'auth_tokens.refresh_ttl_days' => 7, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Schema::table('projects', function (Blueprint $t) {
            $t->string('title')->nullable();
            $t->string('status')->nullable();
        });
        Schema::table('assets', function (Blueprint $t) {
            $t->string('title')->nullable();
            $t->string('asset_type')->nullable();
        });
        Schema::table('export_jobs', function (Blueprint $t) {
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->unsignedBigInteger('output_asset_id')->nullable();
            $t->string('file_name')->nullable();
            $t->string('aspect_ratio')->nullable();
        });
        Schema::table('auth_sessions', function (Blueprint $t) {
            $t->string('token_hash')->nullable();
            $t->string('user_agent')->nullable();
            $t->string('ip_address')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('active_workspace_id')->nullable();
        });
        Schema::create('approvals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('export_job_id')->nullable();
            $t->string('token');
            $t->string('status');
            $t->text('comment')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }

    private function fixture(): array
    {
        $a = Workspace::create(['name' => 'Agency', 'status' => 'active', 'plan_tier' => 'agency', 'credits_topup' => 2000]);
        $u = User::create(['email' => 'owner'.User::count().'@test.test', 'name' => 'Owner', 'role' => 'owner', 'status' => 'active', 'workspace_id' => $a->id]);
        $a->update(['owner_user_id' => $u->id]);
        $c = Workspace::create(['name' => 'Client', 'status' => 'active', 'owner_user_id' => $u->id]);
        $c->forceFill(['parent_workspace_id' => $a->id])->save();

        return [$a, $u, $c];
    }

    private function requestFor(User $u, array $body = [], string $method = 'POST'): Request
    {
        $r = Request::create('/api/v1/test', $method, $body);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    private function membership(User $u, Workspace $w, string $role = 'client'): void
    {
        DB::table('workspace_memberships')->insert(['workspace_id' => $w->id, 'user_id' => $u->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_existing_account_can_join_multiple_clients_without_losing_its_home(): void
    {
        Mail::fake();
        [$a,$owner,$c] = $this->fixture();
        [$b,$person,$other] = $this->fixture();
        $response = app(ClientWorkspaceController::class)->inviteViewer($this->requestFor($owner, ['email' => $person->email, 'role' => 'client_editor']), $c->id);
        $this->assertSame(201, $response->status());
        $this->assertSame($b->id, $person->fresh()->workspace_id);
        $this->assertSame('owner', $person->fresh()->role);
        $this->assertSame('client_editor', app(WorkspaceAccess::class)->role($person, $c));
        $this->assertSame('owner', app(WorkspaceAccess::class)->role($person, $other));
        app(ClientWorkspaceController::class)->removeViewer($this->requestFor($owner), $c->id, $person->id);
        $this->assertNull(app(WorkspaceAccess::class)->role($person, $c));
        $this->assertSame('owner', app(WorkspaceAccess::class)->role($person, $other));
    }

    public function test_refresh_preserves_selected_client_and_effective_role(): void
    {
        [$a,$u,$c] = $this->fixture();
        [$session,$plain] = app(AuthSessionService::class)->create($u, $this->requestFor($u), $c->id);
        [$identity,$rotated,$refresh] = app(AuthSessionService::class)->rotate($plain, $this->requestFor($u));
        $this->assertSame($c->id, $rotated->active_workspace_id);
        $controller = app(AuthController::class);
        $method = new \ReflectionMethod($controller, 'sessionResponse');
        $response = $method->invoke($controller, $identity, $rotated, $refresh)->getData(true);
        $claims = app(JwtService::class)->parse($response['data']['access_token']);
        $this->assertSame($c->id, $claims['workspace_id']);
        $this->assertSame($c->id, $response['data']['user']['workspace_id']);
        $this->assertSame($a->id, $u->fresh()->workspace_id);
    }

    public function test_revoked_membership_rejects_previously_issued_token(): void
    {
        [$a,$owner,$c] = $this->fixture();
        $u = User::create(['workspace_id' => $c->id, 'role' => 'client', 'status' => 'active', 'email' => 'member@test.test']);
        $this->membership($u, $c);
        [$session] = app(AuthSessionService::class)->create($u, $this->requestFor($u));
        $jwt = app(JwtService::class)->issue($u, $c, $session);
        DB::table('workspace_memberships')->where('user_id', $u->id)->update(['revoked_at' => now()]);
        $r = Request::create('/api/v1/projects', 'GET');
        $r->headers->set('Authorization', 'Bearer '.$jwt);
        $this->assertSame(401, app(AuthenticateWithJwt::class)->handle($r, fn () => response()->json([]))->status());
    }

    public function test_monthly_allowance_cannot_be_converted_to_permanent_credits(): void
    {
        [$a,$u,$c] = $this->fixture();
        $a->update(['credits_monthly' => 1000, 'credits_topup' => 0]);
        $this->assertFalse(app(CreditService::class)->transferToClient($a, $c, 500)[0]);
        $this->assertSame(1000, $a->fresh()->credits_monthly);
        $this->assertSame(0, $c->fresh()->credits_topup);
    }

    public function test_reclaim_ledger_uses_actual_amount(): void
    {
        [$a,$u,$c] = $this->fixture();
        $c->update(['credits_topup' => 100, 'funding_mode' => 'funded']);
        app(CreditService::class)->transferToClient($a, $c, -1000);
        $this->assertSame(-100, (int) DB::table('credit_ledger')->where('workspace_id', $a->id)->value('credits'));
        $this->assertSame(2100, $a->fresh()->credits_topup);
    }

    public function test_spend_is_rolled_back_when_cap_accounting_cannot_be_written(): void
    {
        [$a,$u,$c] = $this->fixture();
        Schema::drop('credit_ledger');
        try {
            app(CreditService::class)->deduct($c->id, 100, 'image');
            $this->fail('Expected accounting failure');
        } catch (QueryException) {
        }
        $this->assertSame(2000, $a->fresh()->credits_topup);
    }

    public function test_hub_is_scoped_and_reports_requests_and_spending(): void
    {
        [$a,$u,$c] = $this->fixture();
        $hub = app(ClientHubController::class);
        $hub->requestVideo($this->requestFor($u, ['title' => 'Launch', 'brief' => 'Introduce our new product', 'due_at' => '2026-10-01']), $c->id);
        app(CreditService::class)->deduct($c->id, 40, 'image');
        $data = $hub->show($this->requestFor($u), $c->id)->getData(true)['data'];
        $this->assertCount(1, $data['requests']);
        $this->assertSame(40, $data['spending']['credits']);
        [$b,$other,$foreign] = $this->fixture();
        $this->expectException(ModelNotFoundException::class);
        $hub->show($this->requestFor($u), $foreign->id);
    }

    public function test_client_brief_rejects_foreign_assets(): void
    {
        [$a,$u,$c] = $this->fixture();
        $asset = Asset::create(['workspace_id' => $a->id, 'title' => 'Agency secret', 'asset_type' => 'image']);
        $this->expectException(HttpException::class);
        app(ClientHubController::class)->profile($this->requestFor($u, ['asset_ids' => [$asset->id]]), $c->id);
    }

    public function test_assignment_requires_access_to_this_client(): void
    {
        [$a,$u,$c] = $this->fixture();
        [$b,$other,$foreign] = $this->fixture();
        $hub = app(ClientHubController::class);
        $id = $hub->requestVideo($this->requestFor($u, ['title' => 'Launch', 'brief' => 'A video']), $c->id)->getData(true)['data']['id'];
        $this->expectException(HttpException::class);
        $hub->updateRequest($this->requestFor($u, ['assigned_to_user_id' => $other->id]), $c->id, $id);
    }

    public function test_delivery_requires_approval_for_the_exact_export_and_can_be_revoked(): void
    {
        [$a,$u,$c] = $this->fixture();
        $hub = app(ClientHubController::class);
        $p = Project::create(['workspace_id' => $c->id, 'title' => 'Launch', 'status' => 'ready_for_review']);
        $asset = Asset::create(['workspace_id' => $c->id, 'title' => 'Final', 'asset_type' => 'video']);
        $e = ExportJob::create(['workspace_id' => $c->id, 'project_id' => $p->id, 'status' => 'completed', 'output_asset_id' => $asset->id, 'file_name' => 'final.mp4', 'aspect_ratio' => '9:16']);
        $r = $this->requestFor($u, ['title' => 'Launch delivery', 'export_ids' => [$e->id], 'expires_in_days' => 7]);
        try {
            $hub->createDelivery($r, $c->id);
            $this->fail('Unapproved export should be refused');
        } catch (HttpException $ex) {
            $this->assertSame(422, $ex->getStatusCode());
        }
        Approval::create(['workspace_id' => $c->id, 'project_id' => $p->id, 'export_job_id' => $e->id, 'status' => 'approved', 'token' => 'approved-version']);
        $this->assertSame(201, $hub->createDelivery($r, $c->id)->status());
        $d = DB::table('client_deliveries')->first();
        $this->assertCount(1, $hub->delivery($d->token)->getData(true)['data']['files']);
        $hub->revokeDelivery($r, $c->id, $d->id);
        $this->expectException(HttpException::class);
        $hub->delivery($d->token);
    }

    public function test_review_comments_are_version_bound_and_closed_after_decision(): void
    {
        [$a,$u,$c] = $this->fixture();
        $hub = app(ClientHubController::class);
        $approval = Approval::create(['workspace_id' => $c->id, 'project_id' => 1, 'export_job_id' => 7, 'status' => 'pending', 'token' => 'review-version', 'expires_at' => now()->addDay()]);
        $r = $this->requestFor($u, ['author' => 'Client', 'body' => 'Change this claim', 'at_seconds' => 3.5]);
        $notes = $hub->discussion($r, $approval->token)->getData(true)['data'];
        $this->assertSame(7, $notes[0]['export_job_id']);
        $this->assertSame(3.5, (float) $notes[0]['at_seconds']);
        $approval->update(['status' => 'approved']);
        $this->expectException(HttpException::class);
        $hub->discussion($r, $approval->token);
    }

    public function test_archived_client_is_hidden_and_restorable_without_losing_work(): void
    {
        [$a,$u,$c] = $this->fixture();
        $controller = app(ClientWorkspaceController::class);
        $controller->destroy($this->requestFor($u), $c->id);
        $this->assertSame([], $controller->index($this->requestFor($u))->getData(true)['data']['clients']);
        $controller->update($this->requestFor($u, ['status' => 'active']), $c->id);
        $this->assertCount(1, $controller->index($this->requestFor($u))->getData(true)['data']['clients']);
    }

    public function test_mail_failure_is_not_reported_as_a_successful_invitation(): void
    {
        [$a,$u,$c] = $this->fixture();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Mail unavailable'));
        $response = app(ClientWorkspaceController::class)->inviteViewer($this->requestFor($u,['email' => 'new@test.test']),$c->id);
        $this->assertSame(502,$response->status());
        $this->assertSame('failed',DB::table('workspace_memberships')->value('delivery_status'));
    }
}
