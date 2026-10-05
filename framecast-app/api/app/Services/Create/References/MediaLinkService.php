<?php
namespace App\Services\Create\References;

use App\Models\{Asset, User};
use App\Services\Create\{AttachmentUploadService, ConversationService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Process, RateLimiter};
use Illuminate\Support\Str;

/**
 * A direct link to a video or image file (https://…/demo.mp4, …/product.png) pasted in a brief: the user's own media,
 * such as a product demo or photo. It is downloaded once, stored privately like an upload and attached as a source the
 * video can use. Video posts on X, YouTube and TikTok stay style references (ReferenceLinkService).
 */
class MediaLinkService
{
    public const VIDEO = ['mp4', 'm4v', 'mov', 'webm'];
    public const IMAGE = ['png', 'jpg', 'jpeg', 'webp'];

    private static function extension(string $url): string
    {
        return strtolower(pathinfo((string) parse_url(trim($url), PHP_URL_PATH), PATHINFO_EXTENSION));
    }

    public static function isMediaFile(string $url): bool
    {
        return in_array(self::extension($url), [...self::VIDEO, ...self::IMAGE], true);
    }

    public function add(User $user, string $conversationId, string $url, int $version, string $key): Asset
    {
        app(ConversationService::class)->authorize($user, true);
        $old = Asset::where('workspace_id', $user->workspace_id)->where('metadata_json->create_upload_key', $key)->first();
        if ($old) {
            abort_unless(data_get($old->metadata_json, 'conversation_id') === $conversationId && data_get($old->metadata_json, 'reference_source.requested_url') === $url, 409, 'This request key belongs to a different link.');
            return $old;
        }
        $clean = PageReferenceService::publicUrl($url);
        $limit = 'create-reference:'.$user->workspace_id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($limit, (int) config('create.reference_daily_limit')), 429, 'Daily link limit reached. Try again tomorrow, or upload the file instead.');
        RateLimiter::hit($limit, 86400);

        $dir = sys_get_temp_dir().'/create-link-'.Str::uuid();
        mkdir($dir, 0700);
        try {
            $raw = $dir.'/download';
            $max = (int) config('create.input_file_bytes');
            // No redirects: the checked public address is the one fetched. Streamed to disk with a size cap.
            try {
                $r = Http::withOptions(['stream' => true, 'allow_redirects' => false])->timeout(120)->get($clean);
                abort_unless($r->status() === 200, 422, 'That file could not be downloaded. Check the link is public, or upload the file instead.');
                $body = $r->toPsrResponse()->getBody(); $out = fopen($raw, 'wb'); $size = 0;
                try {
                    while (! $body->eof()) {
                        $chunk = $body->read(1 << 20); $size += strlen($chunk);
                        abort_if($size > $max, 422, 'That file is over 100 MB. Upload a smaller file instead.');
                        fwrite($out, $chunk);
                    }
                } finally { fclose($out); }
            } catch (\Illuminate\Http\Client\ConnectionException) {
                abort(422, 'That file could not be downloaded. Check the link is public, or upload the file instead.');
            }
            abort_unless(is_file($raw) && filesize($raw) > 0, 422, 'That file could not be downloaded. Check the link is public, or upload the file instead.');
            $host = (string) parse_url($clean, PHP_URL_HOST);
            $name = Str::limit(basename((string) parse_url($clean, PHP_URL_PATH)), 120, '');
            $source = ['platform' => 'link', 'requested_url' => $url, 'url' => $clean, 'title' => $name, 'uploader' => $host, 'fetched_at' => now()->toIso8601String()];
            if (in_array(self::extension($url), self::IMAGE, true)) {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($raw);
                abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 422, 'That link is not a PNG, JPEG or WebP image.');
                $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, new UploadedFile($raw, $name, $mime, null, true), 'source', $key, $version);
                $asset->forceFill(['title' => Str::limit($name, 250, '…'), 'metadata_json' => array_merge($asset->metadata_json ?? [], ['reference_source' => $source])])->save();
                return $asset->fresh();
            }
            $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=codec_type', '-of', 'json', $raw]);
            $info = json_decode($probe->output(), true) ?: [];
            $seconds = (float) data_get($info, 'format.duration', 0);
            abort_unless($probe->successful() && collect($info['streams'] ?? [])->contains('codec_type', 'video') && $seconds > 0, 422, 'That link is not a playable video.');
            abort_unless($seconds <= (int) config('create.reference_max_seconds'), 422, 'Videos from a link can be up to 5 minutes long.');
            // Stored as MP4 like every upload; other containers are converted once.
            $file = $dir.'/video.mp4';
            $mp4 = (new \finfo(FILEINFO_MIME_TYPE))->file($raw) === 'video/mp4';
            if ($mp4) rename($raw, $file);
            else abort_unless(Process::timeout(300)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $raw, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-movflags', '+faststart', $file])->successful(), 422, 'That video could not be converted. Upload it as an MP4 instead.');
            $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, new UploadedFile($file, preg_replace('/\.\w+$/', '.mp4', $name), 'video/mp4', null, true), 'source', $key, $version);
            $asset->forceFill(['title' => Str::limit($name, 250, '…'), 'duration_seconds' => (int) round($seconds),
                'metadata_json' => array_merge($asset->metadata_json ?? [], ['reference_source' => $source])])->save();
            return $asset->fresh();
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
}
