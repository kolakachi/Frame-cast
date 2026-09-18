<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Media\MediaTranscriptionService;
use App\Services\Ugc\UgcReference;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Reading a reference records the argument, never the ad. Reproducing someone
 * else's footage is a rights problem and a brittle one — the moment the new
 * script says anything different, copied frames stop fitting. What survives a
 * rewrite is the shape: hook, the cost of the problem, proof, ask.
 */
class UgcReferenceTest extends TestCase
{
    private function reader(string $reply, array $segments = [['start' => 0, 'end' => 3, 'text' => 'I used to sleep badly.']]): UgcReference
    {
        $ai = new class($reply) implements AIGenerationAdapter
        {
            public function __construct(private string $reply) {}

            public function generate(string $k, array $v, int $m = 900, float $t = 0.4, array $o = []): array
            {
                return ['content' => $this->reply];
            }
        };
        $transcription = new class($segments) extends MediaTranscriptionService
        {
            public function __construct(private array $segments) {}

            public function transcribeAssetWithTimestamps(Asset $asset): array
            {
                return ['segments' => $this->segments, 'words' => []];
            }
        };

        return new UgcReference($ai, $transcription);
    }

    private function asset(): Asset
    {
        return new Asset(['duration_seconds' => 30]);
    }

    private function goodReply(): string
    {
        return json_encode(['duration' => 30, 'shape' => 'Problem first, product at the halfway mark.', 'beats' => [
            ['start' => 0, 'end' => 2.5, 'role' => 'hook', 'does' => 'names the frustration before anything is sold',
             'on_screen' => 'a person in bed, lamp on', 'spoken' => 'I used to sleep badly.'],
            ['start' => 2.5, 'end' => 12, 'role' => 'problem', 'does' => 'puts a cost on the problem',
             'on_screen' => 'a clock at 3am', 'spoken' => 'Every night, the same.'],
            ['start' => 12, 'end' => 30, 'role' => 'cta', 'does' => 'asks for the download while relief is fresh',
             'on_screen' => 'an app store page', 'spoken' => 'Try it tonight.'],
        ]]);
    }

    public function test_it_records_what_each_beat_is_for(): void
    {
        $out = $this->reader($this->goodReply())->read($this->asset());

        $this->assertCount(3, $out['beats']);
        $this->assertSame('hook', $out['beats'][0]['role']);
        $this->assertStringContainsString('frustration', $out['beats'][0]['does']);
        $this->assertSame('Problem first, product at the halfway mark.', $out['shape']);
    }

    public function test_an_unknown_role_falls_back_rather_than_reaching_the_planner(): void
    {
        $reply = json_encode(['beats' => [
            ['start' => 0, 'end' => 3, 'role' => 'vibes', 'does' => 'x', 'on_screen' => 'y'],
        ]]);

        $this->assertSame('hook', $this->reader($reply)->read($this->asset())['beats'][0]['role']);
    }

    public function test_it_keeps_at_most_eight_beats(): void
    {
        $beat = ['start' => 0, 'end' => 1, 'role' => 'proof', 'does' => 'x', 'on_screen' => 'y'];
        $reply = json_encode(['beats' => array_fill(0, 12, $beat)]);

        $this->assertCount(8, $this->reader($reply)->read($this->asset())['beats']);
    }

    public function test_a_silent_video_is_refused_rather_than_invented(): void
    {
        $this->expectException(ValidationException::class);
        $this->reader($this->goodReply(), [])->read($this->asset());
    }

    public function test_an_unreadable_reply_spends_nothing_and_says_so(): void
    {
        $this->expectException(ValidationException::class);
        $this->reader('not json')->read($this->asset());
    }

    public function test_a_video_with_no_discernible_structure_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->reader(json_encode(['beats' => []]))->read($this->asset());
    }
}
