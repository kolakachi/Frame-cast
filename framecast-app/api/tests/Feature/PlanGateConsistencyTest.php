<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\WorkspaceUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanGateConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'plan_test', 'database.connections.plan_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('plan_test');
        foreach (['projects', 'export_jobs', 'scenes', 'channels'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id(); $t->integer('workspace_id')->nullable(); $t->integer('project_id')->nullable();
                $t->string('status')->nullable(); $t->float('duration_seconds')->nullable();
                $t->timestamp('completed_at')->nullable(); $t->timestamps();
            });
        }
    }
    private function user(string $tier): User
    {
        return (new User)->forceFill(['id' => 1, 'workspace_id' => 1, 'role' => 'owner'])
            ->setRelation('workspace', (new Workspace)->forceFill(['id' => 1, 'plan_tier' => $tier]));
    }
    public function test_all_plan_families_have_explicit_limits_and_correct_credit_allocations(): void
    {
        $plans = WorkspaceUsageService::plans();
        foreach (CreditService::PLAN_LIMITS as $tier => $limits) {
            $this->assertArrayHasKey($tier, $plans);
            $this->assertSame(CreditService::PLAN_CREDITS[$tier] ?? 0, $plans[$tier]['credits_monthly']);
            if (! in_array($tier, ['studio', 'scale'])) {
                $this->assertSame($limits['max_channels'], $plans[$tier]['channel_limit']);
            }
        }
        $this->assertFalse($plans['lifetime_starter']['watermark']);
        $this->assertSame('exports-priority', CreditService::exportQueueFor('lifetime_agency'));
        $this->assertSame('exports-priority', CreditService::exportQueueFor('pro'));
    }
    public function test_channel_gates_respect_lifetime_and_appsumo_tiers(): void
    {
        DB::table('channels')->insert(['workspace_id' => 1, 'status' => 'active']);
        $service = new WorkspaceUsageService;
        $this->assertTrue($service->hasReachedChannelLimit($this->user('lifetime_starter')));
        $this->assertFalse($service->hasReachedChannelLimit($this->user('appsumo_creator')));
        $this->assertFalse($service->hasReachedChannelLimit($this->user('lifetime_agency')));
    }
    public function test_prior_month_usage_does_not_block_current_month_exports_or_voice(): void
    {
        DB::table('projects')->insert(['id' => 1, 'workspace_id' => 1]);
        for ($i = 0; $i < 50; $i++) {
            DB::table('export_jobs')->insert(['project_id' => 1, 'status' => 'completed', 'completed_at' => now()->subMonth()->startOfMonth()]);
        }
        DB::table('scenes')->insert(['project_id' => 1, 'duration_seconds' => 6000, 'created_at' => now()->subMonth()->startOfMonth()]);
        $service = new WorkspaceUsageService; $user = $this->user('starter');
        $this->assertFalse($service->hasReachedExportLimit($user));
        $this->assertSame(50, $service->exportsRemaining($user));
        $this->assertFalse($service->hasReachedVoiceLimit($user));
        DB::table('export_jobs')->update(['completed_at' => now()]);
        DB::table('scenes')->update(['created_at' => now()]);
        $this->assertTrue($service->hasReachedExportLimit($user));
        $this->assertTrue($service->hasReachedVoiceLimit($user));
    }
    public function test_paid_credits_are_not_blocked_by_an_internal_ai_budget(): void
    {
        $credits = $this->createMock(CreditService::class);
        $credits->method('limitFor')->willReturn(true);
        $this->app->instance(CreditService::class, $credits);
        $this->assertFalse((new WorkspaceUsageService)->hasExceededApiBudget($this->user('starter')));
    }
}
