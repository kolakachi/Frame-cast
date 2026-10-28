<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\Export\ComposerFinalChecks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Process, Schema};
use Tests\TestCase;

/** Checks on a finished composer video: notes for the user, never a reason to fail the export (2026-10-28). */
class ComposerFinalChecksTest extends TestCase
{
    public function test_every_word_spoken_reads_the_transcript_in_order(): void
    {
        $this->assertSame(1.0, ComposerFinalChecks::coverage('Octopuses have three hearts.', 'octopuses have three hearts'));
        $this->assertGreaterThanOrEqual(0.8, ComposerFinalChecks::coverage('Octopuses have three hearts and blue blood.', 'Octopuses have three hearts and blue blood'));
        $this->assertLessThan(0.8, ComposerFinalChecks::coverage('Octopuses have three hearts and blue blood.', 'Octopuses have'), 'most of the line was not said');
    }

    public function test_a_black_silent_short_file_gets_notes_not_a_failure(): void
    {
        config(['database.default' => 'checks_test', 'database.connections.checks_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::create('assets', function (Blueprint $t) { $t->id(); foreach (['transcript_text', 'duration_seconds'] as $c) $t->text($c)->nullable(); $t->timestamps(); });
        $file = sys_get_temp_dir().'/checks-'.uniqid().'.mp4';
        \Symfony\Component\Process\Process::fromShellCommandline('ffmpeg -loglevel error -y -f lavfi -i color=c=black:s=64x64:d=3 -pix_fmt yuv420p '.escapeshellarg($file))->mustRun();
        $project = new Project(['duration_target_seconds' => 60]);
        $checks = collect(ComposerFinalChecks::run($file, $project, collect()))->keyBy('id');
        @unlink($file);
        $this->assertSame('pass', $checks['sound']['status'], 'no narration and no music: silence is right');
        $this->assertSame('note', $checks['blank']['status']);
        $this->assertSame('skipped', $checks['words']['status']);
        $this->assertSame('note', $checks['length']['status']);
        $this->assertStringContainsString('Nothing was cut', $checks['length']['detail']);
    }
}
