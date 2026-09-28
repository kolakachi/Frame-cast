<?php

namespace App\Services\Create;

use App\Models\Asset;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

/** Freeze managed media before quote approval. Never fetch user-provided URLs. */
class InputSnapshotService
{
    private const TYPES = ['image/png' => ['image', 'png'], 'image/jpeg' => ['image', 'jpg'],
        'image/webp' => ['image', 'webp'], 'video/mp4' => ['video', 'mp4'],
        'audio/mpeg' => ['audio', 'mp3'], 'audio/x-wav' => ['audio', 'wav'], 'audio/wav' => ['audio', 'wav']];

    public function inherited(string $conversationId, ?string $revisionId): array
    {
        $seen = [];
        while ($revisionId) {
            abort_if(isset($seen[$revisionId]), 409, 'Invalid revision ancestry.');
            $seen[$revisionId] = true;
            $revision = DB::table('composition_revisions')->where('conversation_id', $conversationId)->where('id', $revisionId)->firstOrFail();
            if ($revision->run_id) {
                $run = DB::table('composition_runs')->where('conversation_id', $conversationId)->where('id', $revision->run_id)->firstOrFail();
                return json_decode($run->input_json, true)['input_files'] ?? [];
            }
            $revisionId = $revision->restored_from_id;
        }
        return [];
    }

    public function capture(int $workspaceId, array $attachments): array
    {
        // Local host lock also protects concurrent PHP requests while copying.
        $disk = Storage::disk('local');
        $disk->makeDirectory('create/locks');
        $lock = fopen($disk->path('create/locks/inputs-'.$workspaceId.'.lock'), 'c');
        abort_unless($lock && flock($lock, LOCK_EX), 503, 'Input storage is busy.');
        try { return $this->captureLocked($workspaceId, $attachments); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function captureLocked(int $workspaceId, array $attachments): array
    {
        $files = []; $total = 0;
        $disk = Storage::disk('local');
        $stored = 0;
        foreach ($disk->allFiles('create/inputs/'.$workspaceId) as $existing) $stored += $disk->size($existing);
        try {
            foreach ($attachments as $attachment) {
                $asset = Asset::where('workspace_id', $workspaceId)->whereKey($attachment->asset_id)->firstOrFail();
                abort_if($asset->status === 'archived', 422, 'An attached asset is no longer available.');
                $url = (string) $asset->storage_url;
                // Only explicitly managed storage. The renderer gets bytes, never storage credentials or remote URLs.
                abort_unless(preg_match('~^(minio|b2)://[^\x00-\x20]+$~D', $url)
                    && ! in_array('..', explode('/', $url), true), 422, 'Upload this attachment to your library before using it.');
                $stream = app(StorageService::class)->readStream($url);
                abort_unless(is_resource($stream), 422, 'The attached file could not be read.');
                $tmp = tmpfile();
                if (! $tmp) { fclose($stream); abort(503, 'Cannot stage media right now.'); }
                try {
                    $limit = min((int) config('create.input_file_bytes'), (int) config('create.input_total_bytes') - $total);
                    $bytes = stream_copy_to_stream($stream, $tmp, max(1, $limit + 1));
                    abort_unless(is_int($bytes) && $bytes > 0 && $bytes <= $limit, 422, 'Attachments exceed the local preview size limit.');
                    abort_if($stored + $total + $bytes > (int) config('create.input_workspace_bytes', 1073741824), 422, 'Local input storage limit reached. Clean up expired previews before adding media.');
                    $file = stream_get_meta_data($tmp)['uri'];
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
                    abort_unless(isset(self::TYPES[$mime]) && self::TYPES[$mime][0] === $asset->asset_type, 422, 'Unsupported attachment format. Use PNG, JPEG, WebP, MP4, MP3 or WAV.');
                    $hash = hash_file('sha256', $file);
                    $name = 'asset-'.$asset->id.'-'.$hash.'.'.self::TYPES[$mime][1];
                    $path = 'create/inputs/'.$workspaceId.'/'.Str::uuid().'/'.$name;
                    rewind($tmp);
                    try {
                        if (! Storage::disk('local')->put($path, $tmp, ['visibility' => 'private'])) throw new \RuntimeException('Input storage unavailable.');
                    } catch (\Throwable $e) {
                        Storage::disk('local')->delete($path);
                        throw $e;
                    }
                    $files[] = ['asset_id' => $asset->id, 'purpose' => $attachment->purpose, 'name' => $name,
                        'sha256' => $hash, 'bytes' => $bytes, 'mime_type' => $mime, 'asset_type' => $asset->asset_type,
                        'storage_path' => $path, 'duration_seconds' => $asset->duration_seconds,
                        'transcript' => mb_substr((string) $asset->transcript_text, 0, 20000)];
                    $total += $bytes;
                } finally { fclose($stream); fclose($tmp); }
            }
            return $files;
        } catch (\Throwable $e) {
            $this->discard($files);
            throw $e;
        }
    }

    public function discard(array $files): void
    {
        foreach ($files as $file) Storage::disk('local')->delete($file['storage_path']);
    }

    public function verify(array $files): void
    {
        foreach ($files as $file) {
            $path = Storage::disk('local')->path($file['storage_path']);
            abort_unless(is_file($path) && ! is_link($path) && filesize($path) === $file['bytes']
                && hash_equals($file['sha256'], hash_file('sha256', $path)), 409, 'An input snapshot is unavailable or changed. Prepare a new quote.');
        }
    }
}
