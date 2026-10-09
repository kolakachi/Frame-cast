<?php

namespace App\Jobs;

use App\Services\Create\CreateStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * A poster frame for a Create version (D6, 2026-10-09): one frame of the finished video, so the dashboard's Recent
 * videos shows the real thing. Kept beside the private previews (create/posters/<revision>.jpg), outside the folders
 * the retention sweep clears. Made once; a missing poster just shows the placeholder.
 */
class MakeCreatePoster implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;
    public int $tries = 1;

    public function __construct(public string $revisionId) {}

    public static function path(string $revisionId): string { return 'create/posters/'.$revisionId.'.jpg'; }

    public function handle(CreateStorage $storage): void
    {
        $revision = DB::table('composition_revisions')->where('id', $this->revisionId)->first(['id', 'artifact_path']);
        if (! $revision || ! str_ends_with((string) $revision->artifact_path, '.mp4') || $storage->exists(self::path($revision->id))) return;
        if (! $storage->exists($revision->artifact_path)) return;
        $src = $storage->path($revision->artifact_path);
        $out = tempnam(sys_get_temp_dir(), 'poster-').'.jpg';
        try {
            // A second and a half in, past most fades from black; a shorter video gives its first frame.
            foreach ([['-ss', '1.5'], []] as $seek) {
                $ok = Process::timeout(60)->run(['ffmpeg', '-y', ...$seek, '-i', $src, '-frames:v', '1', '-vf', "scale='min(540,iw)':-2", '-q:v', '4', $out])->successful();
                if ($ok && @filesize($out)) break;
            }
            if (@filesize($out)) $storage->put(self::path($revision->id), file_get_contents($out));
        } finally {
            @unlink($out);
        }
    }
}
