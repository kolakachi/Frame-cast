<?php

namespace Tests\Unit;

use App\Console\Commands\CreateProviderContracts as C;
use PHPUnit\Framework\TestCase;

class CreateProviderContractsTest extends TestCase
{
    public function test_a_changed_schema_is_reported_and_an_unchanged_one_is_not(): void
    {
        $contract = ['sends' => ['prompt', 'aspect_ratio', 'duration', 'watermark'], 'values' => ['aspect_ratio' => ['9:16', '21:9'], 'duration' => [4, 30]]];
        $schema = fn (array $props, array $required = ['prompt'], array $aspects = ['9:16', '16:9', '21:9'], int $max = 30) => ['components' => ['schemas' => [
            'Input' => ['required' => $required, 'properties' => $props + ['aspect_ratio' => ['allOf' => [['$ref' => '#/components/schemas/aspect_ratio']]], 'duration' => ['type' => 'integer', 'minimum' => 4, 'maximum' => $max]]],
            'aspect_ratio' => ['enum' => $aspects]]]];
        $all = ['prompt' => ['type' => 'string'], 'watermark' => ['type' => 'boolean']];
        $this->assertSame([], C::differences($schema($all), $contract));
        $changed = C::differences($schema(['prompt' => ['type' => 'string'], 'image' => ['type' => 'string']], ['prompt', 'image'], ['9:16', '16:9'], 15), $contract);
        $this->assertSame(4, count($changed), implode("\n", $changed));
        $this->assertStringContainsString("'watermark'", $changed[0]);
        $this->assertStringContainsString("requires 'image'", $changed[1]);
        $this->assertStringContainsString('"21:9"', $changed[2]);
        $this->assertStringContainsString('maximum is now 15', $changed[3]);
        $this->assertSame(['The model or its schema could not be read.'], C::differences(null, $contract));
    }
}
