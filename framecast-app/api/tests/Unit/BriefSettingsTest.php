<?php

namespace Tests\Unit;

use App\Services\Create\BriefSettings;
use Tests\TestCase;

class BriefSettingsTest extends TestCase
{
    private array $video = ['output_kind' => 'video', 'aspect_ratio' => '9:16', 'duration_seconds' => 15, 'language' => 'en', 'audio' => 'original', 'captions' => 'off'];

    public function test_supported_wording_becomes_settings(): void
    {
        $r = BriefSettings::infer('Make it square, 20 seconds, in French, silent, no captions please.', $this->video);
        $this->assertSame(['aspect_ratio' => '1:1', 'duration_seconds' => 20, 'language' => 'fr', 'audio' => 'silent'], $r['changes']);
        $this->assertSame([], $r['questions']);
        $this->assertStringContainsString('square (1:1)', BriefSettings::describe($r['changes']));
    }

    public function test_unchanged_and_unstated_settings_are_left_alone(): void
    {
        $r = BriefSettings::infer('Keep my source audio and make the product pop.', $this->video);
        $this->assertSame([], $r['changes']);
        $this->assertSame([], $r['questions']);
    }

    public function test_unsupported_length_and_language_become_questions(): void
    {
        $r = BriefSettings::infer('A 60 second landscape explainer in Swahili.', $this->video);
        $this->assertSame(['aspect_ratio' => '16:9'], $r['changes']);
        $this->assertCount(2, $r['questions']);
        $this->assertStringContainsString('60 seconds', $r['questions'][0]);
        $this->assertStringContainsString('Swahili', $r['questions'][1]);
        $this->assertCount(1, BriefSettings::infer('a 2 minute video', $this->video)['questions']);
    }

    public function test_quoted_copy_is_not_read_as_settings(): void
    {
        $r = BriefSettings::infer('Callouts "Assembles in 15 minutes" and "Square edges", keep it energetic.', $this->video);
        $this->assertSame([], $r['changes']);
        $this->assertSame([], $r['questions']);
    }

    public function test_image_briefs_ignore_video_only_wording(): void
    {
        $r = BriefSettings::infer('A square product image, 20 seconds, silent.', ['output_kind' => 'image', 'aspect_ratio' => '9:16']);
        $this->assertSame(['aspect_ratio' => '1:1'], $r['changes']);
        $this->assertSame([], $r['questions']);
    }

    public function test_a_list_of_formats_is_content_not_a_format_change(): void
    {
        $settings = ['output_kind' => 'video', 'aspect_ratio' => '16:9', 'duration_seconds' => 30];
        $r = BriefSettings::infer('Make each panel large. Label the format tiles 9:16, 1:1, 4:5 and 16:9. Merge the two closing cards into one.', $settings);
        $this->assertSame([], $r['changes'], 'several ratios named: the video keeps its shape');
        $this->assertSame(['aspect_ratio' => '1:1'], BriefSettings::infer('Make it square instead.', $settings)['changes']);
    }
}
