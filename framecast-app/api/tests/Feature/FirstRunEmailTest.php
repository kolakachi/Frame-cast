<?php

namespace Tests\Feature;

use App\Jobs\ProcessOnboardingEmailsJob;
use App\Mail\MagicLinkMail;
use App\Mail\Onboarding\OnboardingDay1Activation;
use App\Mail\Onboarding\OnboardingDay1FinishSignup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class FirstRunEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'firstrun_test', 'database.connections.firstrun_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('firstrun_test');
        Mail::fake();
        // Pinned so link assertions do not depend on the local environment.
        config(['app.frontend_url' => 'https://app.wyvstudio.com']);

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('plan_tier')->nullable();
            $t->string('intended_plan')->nullable(); $t->string('pending_checkout_plan')->nullable();
            $t->integer('credits_monthly')->default(0); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('name')->nullable(); $t->string('email')->nullable();
            $t->integer('onboarding_step')->default(1);
            $t->timestamp('onboarding_last_sent_at')->nullable(); $t->timestamps();
        });
    }

    private function user(?string $tier = null, array $workspace = []): User
    {
        $ws = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => $tier]);
        if ($workspace) {
            $ws->forceFill($workspace)->save();
        }

        return User::query()->create(['workspace_id' => $ws->id, 'name' => 'Ada', 'email' => 'ada@acme.test']);
    }

    public function test_a_new_signup_gets_one_email_that_both_signs_them_in_and_names_their_plan(): void
    {
        // Registration is passwordless, so this link has to be opened before
        // anything else can happen — a second welcome would compete with it.
        $body = (new MagicLinkMail($this->user(), 'https://app.wyvstudio.com/auth/magic?token=abc',
            firstRun: true, planLabel: config('billing.kelviq.plan_labels')['lifetime_creator']))->render();

        $this->assertStringContainsString('Welcome to WyvStudio', $body);
        $this->assertStringContainsString('auth/magic?token=abc', $body);
        $this->assertStringContainsString('Creator — $199 one-time', $body);
        $this->assertStringContainsString('straight to checkout', $body);
    }

    public function test_a_returning_sign_in_stays_a_bare_link(): void
    {
        $mail = new MagicLinkMail($this->user('studio'), 'https://app.wyvstudio.com/auth/magic?token=xyz');
        $body = $mail->render();

        $this->assertStringNotContainsString('Welcome to WyvStudio', $body);
        $this->assertStringNotContainsString('checkout', $body);
        $this->assertStringContainsString('auth/magic?token=xyz', $body);
        $this->assertSame('Your WyvStudio sign-in link', $mail->envelope()->subject);
    }

    public function test_the_first_run_subject_says_welcome(): void
    {
        $mail = new MagicLinkMail($this->user(), 'https://x.test', firstRun: true);
        $this->assertSame('Welcome to WyvStudio — your sign-in link', $mail->envelope()->subject);
    }

    public function test_an_unrecognised_plan_key_prints_nothing_rather_than_itself(): void
    {
        // The key comes from the client; only a server-side label is rendered.
        $label = config('billing.kelviq.plan_labels')['<script>evil</script>'] ?? null;
        $body = (new MagicLinkMail($this->user(), 'https://x.test', firstRun: true, planLabel: $label))->render();

        $this->assertStringNotContainsString('evil', $body);
        $this->assertStringNotContainsString('straight to checkout', $body);
        // Still tells them what happens next.
        $this->assertStringContainsString('pick a plan', $body);
    }

    public function test_day_one_asks_an_unpaid_signup_to_finish_rather_than_how_their_video_went(): void
    {
        $this->user()->forceFill(['created_at' => now()->subDays(2)])->save();

        (new ProcessOnboardingEmailsJob)->handle();

        Mail::assertQueued(OnboardingDay1FinishSignup::class);
        Mail::assertNotQueued(OnboardingDay1Activation::class);
    }

    public function test_day_one_still_asks_a_paying_customer_about_their_first_video(): void
    {
        $this->user('studio')->forceFill(['created_at' => now()->subDays(2)])->save();

        (new ProcessOnboardingEmailsJob)->handle();

        Mail::assertQueued(OnboardingDay1Activation::class);
        Mail::assertNotQueued(OnboardingDay1FinishSignup::class);
    }

    public function test_the_unpaid_day_one_email_goes_straight_to_checkout_for_the_plan_they_chose(): void
    {
        // The choice is on the workspace, so it survives the device and the
        // cache the browser stash did not.
        $user = $this->user(null, ['intended_plan' => 'lifetime_creator']);
        $body = (new OnboardingDay1FinishSignup($user))->render();

        $this->assertStringContainsString('/continue?plan=lifetime_creator', $body);
        $this->assertStringContainsString('Finish checkout for', $body);
        $this->assertStringContainsString('Creator — $199 one-time', $body);
        // The opening has to agree with the button: they did pick a plan.
        $this->assertStringContainsString('never finished paying', $body);
        $this->assertStringNotContainsString("haven't picked a plan", $body);
        // An escape hatch, so a changed mind is not a dead end.
        $this->assertStringContainsString('app.wyvstudio.com/plans', $body);
    }

    public function test_an_abandoned_checkout_wins_over_the_plan_chosen_at_signup(): void
    {
        // Later intent is better intent.
        $user = $this->user(null, ['intended_plan' => 'starter', 'pending_checkout_plan' => 'lifetime_agency']);
        $body = (new OnboardingDay1FinishSignup($user))->render();

        $this->assertStringContainsString('/continue?plan=lifetime_agency', $body);
        $this->assertStringNotContainsString('plan=starter', $body);
    }

    public function test_with_no_plan_on_record_it_falls_back_to_the_plans_page(): void
    {
        $body = (new OnboardingDay1FinishSignup($this->user()))->render();

        $this->assertStringContainsString('app.wyvstudio.com/plans', $body);
        $this->assertStringNotContainsString('/continue', $body);
        $this->assertStringNotContainsString('Finish checkout', $body);
    }

    public function test_the_unpaid_day_one_email_makes_no_free_trial_promise(): void
    {
        $body = (new OnboardingDay1FinishSignup($this->user()))->render();

        $this->assertStringNotContainsString('free trial.', $body);
        $this->assertStringNotContainsString('free credits', $body);
    }

    public function test_a_plan_that_is_not_sold_never_reaches_the_link(): void
    {
        $user = $this->user(null, ['intended_plan' => '../../evil']);
        $body = (new OnboardingDay1FinishSignup($user))->render();

        $this->assertStringNotContainsString('evil', $body);
        $this->assertStringContainsString('app.wyvstudio.com/plans', $body);
    }
}
