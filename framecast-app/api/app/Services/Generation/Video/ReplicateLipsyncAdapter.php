<?php

namespace App\Services\Generation\Video;

use Illuminate\Support\Facades\Http;

/**
 * Sync Lipsync-2-Pro on Replicate: puts a voice onto a clip's mouth and leaves the rest of the picture as it was.
 *
 * Used on Omni clips (owner, 2026-10-14: "for omni we can use lip sync"): Omni moves best, but it repeats or drops
 * words and makes its own voice per clip. The line is read once by a fixed voice, and that read is synced onto
 * Omni's motion, so the words are exact and every clip of a speaker sounds the same. About $0.66 per 8 seconds.
 */
class ReplicateLipsyncAdapter
{
    public const MODEL = 'sync/lipsync-2-pro';

    /** The clip with the audio's speech on its mouth, as a local file. A clip shorter than the audio plays forward and
     *  back to cover it (bounce), so no word is lost; the caller cuts a longer clip to the audio first. */
    public function sync(string $videoPath, string $audioPath, int $maxSeconds = 900): string
    {
        $token = (string) config('services.replicate.api_token');
        $video = $this->upload($token, $videoPath, 'video/mp4');
        $audio = $this->upload($token, $audioPath, str_ends_with($audioPath, '.wav') ? 'audio/wav' : 'audio/mpeg');
        $prediction = Http::withToken($token)->timeout(60)
            ->post('https://api.replicate.com/v1/models/'.self::MODEL.'/predictions', ['input' => [
                'video' => $video, 'audio' => $audio, 'sync_mode' => 'bounce', 'temperature' => 0.5, 'active_speaker' => false,
            ]])->throw()->json();
        $id = (string) ($prediction['id'] ?? '');
        if ($id === '') throw new \RuntimeException('Lip-sync did not start.');

        $deadline = time() + $maxSeconds;
        while (time() < $deadline) {
            $p = Http::withToken($token)->timeout(30)->get('https://api.replicate.com/v1/predictions/'.$id)->json();
            $status = (string) ($p['status'] ?? '');
            if ($status === 'succeeded') {
                $url = is_array($p['output'] ?? null) ? (string) ($p['output'][0] ?? '') : (string) ($p['output'] ?? '');
                $path = tempnam(sys_get_temp_dir(), 'lipsync-').'.mp4';
                file_put_contents($path, file_get_contents($url));
                if (! filesize($path)) throw new \RuntimeException('Lip-sync came back empty.');
                return $path;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new \RuntimeException('Lip-sync failed: '.mb_substr((string) ($p['error'] ?? 'no detail'), 0, 200));
            }
            sleep(5);
        }
        throw new \RuntimeException('Lip-sync did not finish in time.');
    }

    private function upload(string $token, string $path, string $mime): string
    {
        $out = Http::withToken($token)->timeout(120)
            ->attach('content', (string) file_get_contents($path), basename($path), ['Content-Type' => $mime])
            ->post('https://api.replicate.com/v1/files')->throw()->json();
        $url = (string) ($out['urls']['get'] ?? '');
        if ($url === '') throw new \RuntimeException('Lip-sync upload failed.');
        return $url;
    }
}
