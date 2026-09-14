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

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('plan_tier')->nullable();
            $t->integer('credits_monthly')->default(0); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('name')->nullable(); $t->string('email')->nullable();
            $t->integer('onboarding_step')->default(1);
            $t->timestamp('onboarding_last_sent_at')->nullable(); $t->timestamps();
        });
    }

    private function user(?string $tier = null): User
    {
        $ws = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => $tier]);

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

    public function test_the_unpaid_day_one_email_points_at_checkout_and_makes_no_free_trial_promise(): void
    {
        $body = (new OnboardingDay1FinishSignup($this->user()))->render();

        $this->assertStringContainsString('app.wyvstudio.com/plans', $body);
        $this->assertStringNotContainsString('free trial.', $body);
        $this->assertStringNotContainsString('free credits', $body);
    }
}
