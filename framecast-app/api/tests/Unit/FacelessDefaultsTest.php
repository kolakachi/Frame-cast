<?php

namespace Tests\Unit;

use App\Jobs\ScoreHooksJob;
use App\Models\Niche;
use App\Services\CreditService;
use App\Services\Projects\ProjectCreationService;
use App\Services\ScenePacing;
use Tests\TestCase;

/** Faceless niches set the pace, narrator, captions and music; the opening line is judged with the hooks (2026-10-28). */
class FacelessDefaultsTest extends TestCase
{
    public function test_every_faceless_niche_has_a_playbook_and_its_defaults(): void
    {
        foreach (Niche::FACELESS as $slug) {
            $this->assertNotSame(Niche::PLAYBOOK['_default'], Niche::guidanceForSlug($slug), $slug.' has its own playbook');
            $d = Niche::defaultsFor($slug);
            $this->assertNotEmpty($d['voice'] ?? null, $slug.' narrator');
            $this->assertArrayHasKey(($d['voice'] ?? ''), \App\Services\Generation\TTS\GeminiVoices::VOICES, $slug.' narrator is a real Gemini voice');
            $this->assertNotEmpty($d['captions']['animation'] ?? null, $slug.' caption look');
        }
        $this->assertStringContainsString('never invent', Niche::guidanceForSlug('facts'));
        $this->assertStringNotContainsString('CTA', Niche::guidanceForSlug('facts'), 'a facts video ends on a loop or a follow line, not a sales pitch');
    }

    public function test_a_niche_sets_the_pace_and_the_price_follows(): void
    {
        $this->assertSame(20, ScenePacing::targetScenes(60, 'ai_images', false, null, Niche::secondsFor('facts')), 'facts cut every 3 s');
        $this->assertSame(12, ScenePacing::targetScenes(60, 'ai_images'), 'no niche: every 5 s');
        $this->assertSame(3, ScenePacing::targetScenes(60, 'waveform', false, null, 3.0), 'an audiogram keeps its long waveform');
        $credits = app(CreditService::class);
        $facts = $credits->estimateProject('prompt', 'x', 'ai_images', 'medium', 60, nicheSlug: 'facts');
        $plain = $credits->estimateProject('prompt', 'x', 'ai_images', 'medium', 60);
        $this->assertGreaterThan($plain['credits_max'], $facts['credits_max'], 'faster cuts mean more images, and the price says so');
    }

    public function test_mood_chips_map_to_the_library_moods(): void
    {
        $this->assertSame('dark', ProjectCreationService::libraryMood('Dark, Cinematic'));
        $this->assertSame('epic', ProjectCreationService::libraryMood('Dramatic'));
        $this->assertSame('upbeat', ProjectCreationService::libraryMood('Energetic'));
        $this->assertSame('calm', ProjectCreationService::libraryMood(''));
    }

    public function test_only_the_opening_line_is_swapped_for_a_stronger_hook(): void
    {
        $script = 'Octopuses are interesting animals. They have three hearts and blue blood.';
        $this->assertSame('Octopuses are interesting animals.', ScoreHooksJob::openingLine($script));
        $this->assertSame('An octopus has three hearts, and that is not the weirdest part. They have three hearts and blue blood.',
            ScoreHooksJob::replaceOpening($script, 'Octopuses are interesting animals.', 'An octopus has three hearts, and that is not the weirdest part.'));
    }
}
