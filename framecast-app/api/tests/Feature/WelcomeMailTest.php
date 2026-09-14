<?php

namespace Tests\Feature;

use App\Mail\Onboarding\OnboardingDay0Welcome;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Onboarding\WelcomeMail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class WelcomeMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'welcome_test', 'database.connections.welcome_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('welcome_test');
        Mail::fake();

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('plan_tier')->nullable(); $t->string('plan_status')->nullable();
            $t->string('plan_source')->nullable(); $t->integer('credits_monthly')->default(0);
            $t->timestamp('welcome_email_sent_at')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('name')->nullable(); $t->string('email')->nullable(); $t->timestamps();
        });
    }

    private function paidWorkspace(array $o = [], bool $withOwner = true): Workspace
    {
        $ws = Workspace::query()->create(array_merge([
            'name' => 'Acme', 'plan_tier' => 'studio', 'plan_status' => 'active', 'credits_monthly' => 4000,
        ], $o));
        if ($withOwner) {
            $user = User::query()->create(['workspace_id' => $ws->id, 'name' => 'Ada', 'email' => 'ada@acme.test']);
            $ws->forceFill(['owner_user_id' => $user->id])->save();
        }

        return $ws->fresh();
    }

    public function test_a_paid_account_gets_the_welcome(): void
    {
        WelcomeMail::sendOnce($this->paidWorkspace());
        Mail::assertQueued(OnboardingDay0Welcome::class, 1);
    }

    public function test_a_repeated_plan_event_does_not_send_it_again(): void
    {
        // subscription.updated fires on any change, and webhooks are redelivered.
        $ws = $this->paidWorkspace();
        WelcomeMail::sendOnce($ws);
        WelcomeMail::sendOnce($ws->fresh());
        WelcomeMail::sendOnce($ws->fresh());
        Mail::assertQueued(OnboardingDay0Welcome::class, 1);
    }

    public function test_an_account_that_already_had_the_old_registration_email_is_not_mailed_again(): void
    {
        // What the migration's backfill stands in for. Forced, not mass
        // assigned — the column is deliberately not fillable, since the only
        // legitimate writer is the claim in WelcomeMail.
        $ws = $this->paidWorkspace();
        $ws->forceFill(['welcome_email_sent_at' => now()->subMonth()])->save();

        WelcomeMail::sendOnce($ws->fresh());
        Mail::assertNothingQueued();
    }

    public function test_a_workspace_with_no_addressable_owner_keeps_its_claim_open(): void
    {
        // Otherwise a provisioning race would burn the one send on nobody.
        $ws = $this->paidWorkspace([], withOwner: false);
        WelcomeMail::sendOnce($ws);
        Mail::assertNothingQueued();
        $this->assertNull($ws->fresh()->welcome_email_sent_at);

        $user = User::query()->create(['workspace_id' => $ws->id, 'name' => 'Ada', 'email' => 'ada@acme.test']);
        $ws->forceFill(['owner_user_id' => $user->id])->save();
        WelcomeMail::sendOnce($ws->fresh());
        Mail::assertQueued(OnboardingDay0Welcome::class, 1);
    }

    public function test_the_email_quotes_the_plan_they_bought_rather_than_free_credits(): void
    {
        $ws = $this->paidWorkspace(['plan_tier' => 'studio', 'credits_monthly' => 4000]);
        $body = $this->render($ws);

        $this->assertStringContainsString('4,000 credits', $body);
        $this->assertStringContainsString('renewed every month', $body);
        $this->assertStringContainsString('Studio', $body);
        // The old copy promised these to everyone, including plan-gated signups.
        $this->assertStringNotContainsString('200 free credits', $body);
        $this->assertStringNotContainsString('No credit card', $body);
    }

    public function test_a_one_time_bucket_is_not_described_as_renewing(): void
    {
        // Lifetime and AppSumo grant once; saying "every month" is a promise
        // support has to walk back.
        $ws = $this->paidWorkspace(['plan_tier' => 'creator', 'credits_monthly' => 0, 'plan_source' => 'lifetime']);
        $body = $this->render($ws);
        $this->assertStringNotContainsString('renewed every month', $body);
    }

    private function render(Workspace $ws): string
    {
        $user = User::query()->where('workspace_id', $ws->id)->firstOrFail();

        return (new OnboardingDay0Welcome($user, $ws))->render();
    }
}
