<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Create\CreateController;
use App\Services\Analytics\DownloadLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Mockery;
use Tests\TestCase;

class VideoDownloadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'dl_test', 'database.connections.dl_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('dl_test');
        (include base_path('database/migrations/2026_10_15_200000_create_video_downloads_table.php'))->up();
        Schema::create('workspaces', fn (Blueprint $t) => [$t->id(), $t->string('status')->nullable(), $t->timestamps()]);
        Schema::create('create_conversations', fn (Blueprint $t) => [$t->string('id'), $t->integer('workspace_id')]);
        Schema::create('composition_revisions', fn (Blueprint $t) => [$t->string('id'), $t->string('conversation_id'), $t->string('artifact_path')->nullable(), $t->integer('number')]);
        DB::table('workspaces')->insert(['id' => 3, 'status' => 'active']);
        DB::table('create_conversations')->insert(['id' => 'conv-1', 'workspace_id' => 3]);
        DB::table('composition_revisions')->insert(['id' => 'rev-1', 'conversation_id' => 'conv-1', 'artifact_path' => 'v1.mp4', 'number' => 2]);
        $file = tempnam(sys_get_temp_dir(), 'dl-').'.mp4';
        file_put_contents($file, 'video-bytes');
        $storage = Mockery::mock(\App\Services\Create\CreateStorage::class);
        $storage->shouldReceive('exists')->andReturn(true);
        $storage->shouldReceive('path')->andReturn($file);
        $this->app->instance(\App\Services\Create\CreateStorage::class, $storage);
    }

    private function video(array $query, array $headers = [])
    {
        $r = Request::create('/media/create/rev-1', 'GET', $query);
        foreach ($headers as $k => $v) $r->headers->set($k, $v);
        return app(CreateController::class)->signedVideo($r, 'rev-1');
    }

    public function test_a_create_download_is_recorded_and_playback_is_not(): void
    {
        $this->video([]);
        $this->video([], ['Range' => 'bytes=0-']);
        $this->assertSame(0, DB::table('video_downloads')->count(), 'playing the video is not a download');

        $response = $this->video(['download' => 1, 'u' => 7]);
        $this->assertStringContainsString('attachment; filename="wyvstudio-v2.mp4"', (string) $response->headers->get('Content-Disposition'));
        $this->video(['download' => 1, 'u' => 7], ['Range' => 'bytes=4000-']);
        $row = DB::table('video_downloads')->first();
        $this->assertSame(1, DB::table('video_downloads')->count(), 'the rest of the same file is not a second download');
        $this->assertSame([3, 7, 'create', 'app', 'conv-1', 'rev-1'], [(int) $row->workspace_id, (int) $row->user_id, $row->source, $row->via, $row->conversation_id, $row->revision_id]);
    }

    public function test_the_logger_keeps_sources_and_ways_to_known_values(): void
    {
        DownloadLog::record(3, null, 'ugc', 'api', ['project_id' => 9]);
        DownloadLog::record(3, 5, 'something', 'browser');
        $this->assertSame([['ugc', 'api'], ['editor', 'app']], DB::table('video_downloads')->orderBy('id')->get()->map(fn ($r) => [$r->source, $r->via])->all());
        $this->assertSame(['u' => 5, 'via' => 'api'], DownloadLog::linkParams(5, true));
        $this->assertSame(['u' => 5], DownloadLog::linkParams(5));
    }
}
