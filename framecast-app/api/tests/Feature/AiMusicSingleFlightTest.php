<?php

namespace Tests\Feature;

use App\Jobs\GenerateAIMusicJob;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A customer was charged twice for one piece of music: the editor stopped
 * waiting before the job's own timeout, told him it had failed, and the retry
 * he was invited to make generated and billed the same track again. The claim
 * below is what makes the second run impossible while the first is still live.
 */
class AiMusicSingleFlightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(GenerateAIMusicJob::inFlightKey(4242));
    }

    public function test_the_first_request_claims_the_project(): void
    {
        $this->assertTrue(Cache::add(GenerateAIMusicJob::inFlightKey(4242), true, GenerateAIMusicJob::LOCK_SECONDS));
    }

    public function test_a_second_request_cannot_claim_it_while_the_first_runs(): void
    {
        Cache::add(GenerateAIMusicJob::inFlightKey(4242), true, GenerateAIMusicJob::LOCK_SECONDS);

        $this->assertFalse(
            Cache::add(GenerateAIMusicJob::inFlightKey(4242), true, GenerateAIMusicJob::LOCK_SECONDS),
            'a retry while music is in flight must not start a second charged run',
        );
    }

    public function test_finishing_frees_the_project_so_a_real_retry_still_works(): void
    {
        Cache::add(GenerateAIMusicJob::inFlightKey(4242), true, GenerateAIMusicJob::LOCK_SECONDS);
        // What the job does in finally(), on success and on failure alike.
        Cache::forget(GenerateAIMusicJob::inFlightKey(4242));

        $this->assertTrue(
            Cache::add(GenerateAIMusicJob::inFlightKey(4242), true, GenerateAIMusicJob::LOCK_SECONDS),
            'a failed run must not block the retry it is the reason for',
        );
    }

    public function test_projects_do_not_block_each_other(): void
    {
        Cache::add(GenerateAIMusicJob::inFlightKey(4242), true, GenerateAIMusicJob::LOCK_SECONDS);

        $this->assertTrue(Cache::add(GenerateAIMusicJob::inFlightKey(4243), true, GenerateAIMusicJob::LOCK_SECONDS));
        Cache::forget(GenerateAIMusicJob::inFlightKey(4243));
    }

    public function test_the_claim_outlives_the_jobs_own_timeout(): void
    {
        $job = new \ReflectionClass(GenerateAIMusicJob::class);
        $timeout = $job->getDefaultProperties()['timeout'];

        $this->assertGreaterThan(
            $timeout,
            GenerateAIMusicJob::LOCK_SECONDS,
            'the claim must not lapse while the job is still allowed to run',
        );
    }
}
