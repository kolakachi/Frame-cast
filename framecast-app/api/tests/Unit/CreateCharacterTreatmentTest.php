<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Create\PlanMediaExecutor;
class CreateCharacterTreatmentTest extends TestCase
{
    public function test_requested_style_changes_texture_while_preserving_identity(): void
    {
        $prompt = PlanMediaExecutor::characterTreatment('halftone pixel-art illustration');
        $this->assertStringContainsString('halftone pixel-art illustration', $prompt);
        $this->assertStringContainsString('not the identity', $prompt);
        $this->assertStringContainsString('Do not return the original photo', $prompt);
        $this->assertStringNotContainsString('details and texture', $prompt);
        $this->assertStringContainsString('details and texture', PlanMediaExecutor::characterTreatment(''));
    }
}
