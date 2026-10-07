<?php
namespace App\Services\Create\References;

use App\Models\{Asset, User};
use App\Services\Create\{AttachmentUploadService, ConversationService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Process, RateLimiter};
use Illuminate\Support\Str;

/**
 * A public post the user pastes as a style reference. It is fetched once,
 * stored privately like an upload, attached with purpose "reference" (never
 * rendered) and studied so the planner and agent can borrow its pacing and
 * look without copying its content.
 */
class ReferenceLinkService
{
    public function add(User $user, string $conversationId, string $url, int $version, string $key): Asset
    {
        app(ConversationService::class)->authorize($user, true);
        app(\App\Services\Create\AdmissionControl::class)->assertOpen();
        $old = Asset::where('workspace_id', $user->workspace_id)->where('metadata_json->create_upload_key', $key)->first();
        if ($old) {
            abort_unless(data_get($old->metadata_json, 'conversation_id') === $conversationId && data_get($old->metadata_json, 'reference_source.requested_url') === $url, 409, 'This request key belongs to a different link.');
            return $old;
        }
        [$platform, $clean] = $this->validate($url);
        $limit = 'create-reference:'.$user->workspace_id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($limit, (int) config('create.reference_daily_limit')), 429, 'Daily reference limit reached. Try again tomorrow.');
        RateLimiter::hit($limit, 86400);

        $dir = sys_get_temp_dir().'/create-ref-'.Str::uuid();
        mkdir($dir, 0700);
        try {
            $info = $this->probe($clean);
            $file = $this->download($clean, $dir);
            $upload = new UploadedFile($file, Str::limit($platform.' reference', 60, '').'.mp4', 'video/mp4', null, true);
            $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, $upload, 'reference', $key, $version);
            $source = ['platform' => $platform, 'requested_url' => $url, 'url' => $clean, 'title' => Str::limit((string) ($info['title'] ?? ''), 200, '…'),
                'uploader' => Str::limit((string) ($info['uploader'] ?? ''), 100, '…'), 'fetched_at' => now()->toIso8601String()];
            $asset->forceFill(['title' => Str::limit(ucfirst($platform).' · '.($source['uploader'] ?: 'reference'), 250, '…'),
                'duration_seconds' => isset($info['duration']) ? (int) round((float) $info['duration']) : null,
                'metadata_json' => array_merge($asset->metadata_json ?? [], ['reference_source' => $source])])->save();
            // Studying it is best-effort: a failed analysis still leaves a usable reference.
            try {
                $analysis = app(ReferenceAnalyzer::class)->analyze($file, $source, (float) ($info['duration'] ?? 0));
                $asset->forceFill(['metadata_json' => array_merge($asset->metadata_json ?? [], ['reference_analysis' => $analysis])])->save();
            } catch (\Throwable $e) {
                report($e);
            }
            return $asset->fresh();
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    /** @return array{0:string,1:string} platform and the URL without tracking noise */
    public function validate(string $url): array
    {
        $parts = parse_url(trim($url));
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['port']), 422, 'Paste a public https link from X, YouTube or TikTok.');
        $host = strtolower($parts['host']);
        abort_unless(in_array($host, config('create.reference_hosts'), true), 422, 'Links from X, YouTube and TikTok are supported.');
        $platform = str_contains($host, 'tiktok') ? 'tiktok' : (str_contains($host, 'youtu') ? 'youtube' : 'x');
        $path = (string) ($parts['path'] ?? '/');
        // X edit history pages point at the same post.
        if ($platform === 'x') $path = preg_replace('~/history/?$~', '', $path);
        $query = '';
        if ($platform === 'youtube' && isset($parts['query'])) {
            parse_str($parts['query'], $q);
            if (isset($q['v']) && preg_match('/^[\w-]{6,20}$/', $q['v'])) $query = '?v='.$q['v'];
        }
        abort_unless(preg_match('~^/[\w@./%-]*$~', $path), 422, 'That link does not look like a post.');
        return [$platform, 'https://'.$host.$path.$query];
    }

    private function base(): array
    {
        // No config files, cookies, playlists or post-processing hooks.
        return [(string) config('create.ytdlp_path'), '--ignore-config', '--no-playlist', '--no-warnings', '--no-progress', '--no-cache-dir', '--socket-timeout', '20'];
    }

    private function probe(string $url): array
    {
        $r = Process::timeout(45)->run([...$this->base(), '-J', $url]);
        abort_unless($r->successful(), 422, 'That post could not be read. Check that it is public and contains a video.');
        $info = json_decode(trim($r->output()), true);
        abort_unless(is_array($info) && ! ($info['is_live'] ?? false), 422, 'That post has no recorded video to use.');
        $d = (float) ($info['duration'] ?? 0);
        abort_unless($d > 0 && $d <= config('create.reference_max_seconds'), 422, 'References can be up to 5 minutes long.');
        return $info;
    }

    private function download(string $url, string $dir): string
    {
        $r = Process::timeout(90)->run([...$this->base(), '--max-filesize', '90M', '-f', 'best[height<=720][ext=mp4]/best[ext=mp4]/best[height<=720]',
            '--remux-video', 'mp4', '-o', $dir.'/reference.%(ext)s', $url]);
        $file = $dir.'/reference.mp4';
        abort_unless($r->successful() && is_file($file) && filesize($file) > 0, 422, 'The video could not be fetched. Try again, or upload the file instead.');
        return $file;
    }
}
