<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Services\Generation\ReplicateFiles;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A file a model could not fetch from our storage is handed over in Replicate's own store (first-session fix 6). */
class ReplicateFilesTest extends TestCase
{
    public function test_a_voice_file_is_copied_with_its_type(): void
    {
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('get')->andReturn('mp3-bytes');
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        Http::fake(['api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/v1']])]);
        $asset = (new Asset)->forceFill(['storage_url' => 'b2://x/voice.mp3', 'mime_type' => 'audio/mpeg']);
        $this->assertSame('https://api.replicate.com/v1/files/v1', ReplicateFiles::copy($asset, 'voice'));
        Http::assertSent(fn ($r) => str_contains($r->body(), 'filename="voice.mp3"') && str_contains($r->body(), 'audio/mpeg'));
    }

    public function test_an_unreadable_file_fails_plainly(): void
    {
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('get')->andReturn(null);
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        $this->expectExceptionMessage('The image could not be read to retry.');
        ReplicateFiles::copy((new Asset)->forceFill(['storage_url' => 'b2://x/a.png', 'mime_type' => 'image/png']), 'image');
    }
}
