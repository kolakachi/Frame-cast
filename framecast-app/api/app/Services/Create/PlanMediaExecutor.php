<?php
namespace App\Services\Create;

use App\Models\{Asset, BrandKit, VoiceProfile};
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Generation\Video\I2VAdapter;
use App\Services\Generation\Visual\VisualProviderAdapter;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\{Http, Process};
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes one plan item with the app's existing adapters and returns a local
 * file. Pricing and charging are PlanMediaService's job; this only produces.
 */
class PlanMediaExecutor
{
    public const KINDS = ['stock_video', 'stock_image', 'ai_image', 'animate_image', 'voiceover', 'cloned_voiceover', 'music', 'sfx', 'character_poses', 'brand_kit'];

    /** @return array{path:string,mime:string,title:string,provider_id:string,note?:string,brand?:array} */
    public function produce(string $kind, string $description, array $ctx, string $dir): array
    {
        $portrait = in_array($ctx['aspect_ratio'] ?? '9:16', ['9:16', '4:5'], true);
        return match ($kind) {
            'stock_video', 'stock_image' => $this->stock($kind, $description, $portrait, $dir),
            'ai_image' => $this->aiImage($description, $ctx, $dir),
            'animate_image' => $this->animate($description, $ctx, $dir),
            'voiceover', 'cloned_voiceover' => $this->voice($kind, $ctx, $dir),
            'library_music' => $this->music($description, $ctx, $dir),
            'music' => $this->generatedMusic($description, $ctx, $dir),
            'sfx' => $this->soundSheet($description, $dir),
            'character_poses' => $this->characterPoses($description, $ctx, $dir),
            'brand_kit' => $this->brand($ctx, $dir),
            default => throw new RuntimeException('This plan item cannot be made here.'),
        };
    }

    private function stock(string $kind, string $q, bool $portrait, string $dir): array
    {
        $m = app(VisualProviderAdapter::class)->match(self::searchTerms($q), $portrait ? 'portrait' : 'landscape', $kind === 'stock_video' ? 'stock_clip' : 'image_montage');
        if (($m['provider_key'] ?? '') === 'placeholder' || empty($m['asset_url'])) throw new RuntimeException('No stock match was found for this item.');
        $video = $kind === 'stock_video';
        $path = $this->fetch((string) $m['asset_url'], $dir.'/stock.'.($video ? 'mp4' : 'jpg'));
        return ['path' => $path, 'mime' => $video ? 'video/mp4' : 'image/jpeg', 'title' => 'Stock · '.Str::limit($q, 60, '…'),
            'provider_id' => substr(($m['provider_key'] ?? 'stock').'-'.($m['provider_asset_id'] ?? Str::uuid()), 0, 150)];
    }

    /** Stock search works on a few subject words, not a shot description. */
    public static function searchTerms(string $q): string
    {
        $skip = ['vertical', 'horizontal', 'portrait', 'landscape', 'slow', 'motion', 'slowmotion', 'fast', 'shot', 'footage', 'clip', 'video', 'photo', 'image', 'stock', 'licensed',
            'background', 'close', 'closeup', 'up', 'macro', 'dark', 'bright', 'moody', 'cinematic', 'aerial', 'wide', 'tight', 'the', 'a', 'an', 'of', 'with', 'over', 'on', 'in', 'and', 'for', 'at', 'to', 'into', 'from', 'being', 'its', 'their', 'our'];
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)), fn ($w) => $w !== '' && ! in_array($w, $skip, true)));
        return implode(' ', array_slice($words, 0, 4)) ?: Str::limit($q, 60, '');
    }

    private function aiImage(string $prompt, array $ctx, string $dir): array
    {
        $r = app(ImageAdapterFactory::class)->resolve(null)->generate($prompt, 'cinematic', $ctx['aspect_ratio'] ?? '9:16');
        $path = $dir.'/image.png';
        if (! empty($r['image_b64'])) file_put_contents($path, base64_decode($r['image_b64']));
        elseif (! empty($r['image_url'])) $this->fetch((string) $r['image_url'], $path);
        else throw new RuntimeException('The image model returned nothing.');
        return ['path' => $path, 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($path), 'title' => 'AI image · '.Str::limit($prompt, 60, '…'), 'provider_id' => 'img-'.Str::uuid()];
    }

    /** Animates the first supplied photo; the plan item describes the motion. */
    private function animate(string $prompt, array $ctx, string $dir): array
    {
        $photo = collect($ctx['source_images'] ?? [])->first();
        if (! $photo) throw new RuntimeException('Animation needs a photo you supplied. Attach one and plan again.');
        // Replicate accepts small inline images; keep the still under 1 MB.
        $small = $dir.'/still.jpg';
        $p = Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $photo, '-vf', 'scale=min(1024\,iw):-2', '-q:v', '5', $small]);
        if (! $p->successful() || filesize($small) > 1_000_000) throw new RuntimeException('The photo could not be prepared for animation.');
        $r = app(I2VAdapter::class)->animate('data:image/jpeg;base64,'.base64_encode(file_get_contents($small)), $prompt, 'quick', 5, ['resolution' => '480p']);
        if (empty($r['video_url'])) throw new RuntimeException('The animation model returned nothing.');
        return ['path' => $this->fetch((string) $r['video_url'], $dir.'/motion.mp4'), 'mime' => 'video/mp4', 'title' => 'Animation · '.Str::limit($prompt, 60, '…'), 'provider_id' => 'i2v-'.Str::uuid()];
    }

    /** Narrates the approved on-screen lines; the plan never invents spoken claims. */
    private function voice(string $kind, array $ctx, string $dir): array
    {
        // The approved script, or the approved on-screen lines when there is no script.
        $lines = ! empty($ctx['narration']) ? $ctx['narration'] : ($ctx['approved_copy'] ?? []);
        $text = trim(implode(' ', array_map(fn ($l) => preg_match('/[.!?…]$/u', trim((string) $l)) ? trim((string) $l) : trim((string) $l).'.', $lines)));
        if ($text === '') throw new RuntimeException('Narration needs approved lines. Add the exact words to say and plan again.');
        $text = self::pronounce($text, (int) $ctx['workspace_id']);
        $opts = ['provider' => 'gemini'];
        $voice = \App\Services\Generation\TTS\GeminiVoices::resolve($ctx['voice'] ?? null);
        if ($kind === 'cloned_voiceover' || ($ctx['voice'] ?? null) === 'clone') {
            $profile = VoiceProfile::where('workspace_id', $ctx['workspace_id'])->where('is_cloned', true)->latest('id')->first();
            $sample = $profile?->source_asset_id ? Asset::find($profile->source_asset_id) : null;
            if (! $profile || ! $sample?->storage_url) throw new RuntimeException('No cloned voice is ready in this workspace.');
            $opts = ['provider' => 'clone', 'clone_audio_url' => app(StorageService::class)->url((string) $sample->storage_url)];
            $voice = (string) $profile->provider_voice_key;
        }
        $r = app(TTSAdapter::class)->synthesize(Str::limit($text, 600, ''), $ctx['language'] ?? 'en', $voice, 1.0, $opts);
        if (empty($r['audio_url'])) throw new RuntimeException('The voice model returned nothing.');
        $path = $this->fetch((string) $r['audio_url'], $dir.'/voice.audio');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['audio/mpeg', 'audio/wav', 'audio/x-wav'], true)) {
            $wav = $dir.'/voice.wav';
            if (! Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $path, $wav])->successful()) throw new RuntimeException('The narration could not be converted.');
            [$path, $mime] = [$wav, 'audio/x-wav'];
        }
        return ['path' => $path, 'mime' => $mime, 'title' => 'Narration · '.Str::limit($text, 60, '…'), 'provider_id' => 'tts-'.Str::uuid()];
    }

    /** An original instrumental bed from ElevenLabs Music, one second longer than the video. */
    private function generatedMusic(string $description, array $ctx, string $dir): array
    {
        $seconds = max(5, (int) ($ctx['duration_seconds'] ?? 15)) + 1;
        $url = $this->replicate('elevenlabs/music', ['prompt' => Str::limit('Instrumental background music for a short video: '.$description.'. No vocals, steady energy, clean ending.', 900, ''),
            'music_length_ms' => $seconds * 1000, 'force_instrumental' => true, 'output_format' => 'mp3_high_quality']);
        $path = self::fillMusic($this->fetch($url, $dir.'/music.mp3'), $seconds, $dir);
        return ['path' => $path, 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($path), 'title' => 'Music · '.Str::limit($description, 60, '…'), 'provider_id' => 'music-'.Str::uuid()];
    }

    public const DEFAULT_POSES = ['talking, mid-sentence, friendly', 'waving hello', 'pointing to one side', 'surprised', 'thumbs up'];

    /**
     * One character in several poses, identity kept by Nano Banana Pro from a
     * single reference, each cut out on a transparent background. The
     * reference is a saved workspace character named in the description, the
     * first supplied image, or a new original character drawn first.
     */
    private function characterPoses(string $description, array $ctx, string $dir): array
    {
        [$who, $list] = array_pad(explode(':', $description, 2), 2, '');
        $poses = array_values(array_slice(array_filter(array_map('trim', preg_split('/[,;\n]+/', $list))), 0, 5)) ?: self::DEFAULT_POSES;
        $nano = app(\App\Services\Generation\Image\NanoBananaProImageAdapter::class);
        $refUrl = null; $name = 'Character';
        $saved = \App\Models\Character::where('workspace_id', $ctx['workspace_id'])->where('status', '!=', 'archived')->get()
            ->first(fn ($c) => $c->name && str_contains(mb_strtolower($description), mb_strtolower($c->name)));
        $refAsset = $saved ? Asset::find($saved->reference_asset_id ?: $saved->preview_asset_id) : null;
        if ($refAsset?->storage_url) {
            $refUrl = $this->replicateUpload((string) app(StorageService::class)->get((string) $refAsset->storage_url), $refAsset->mime_type ?: 'image/png');
            $name = $saved->name;
        } elseif ($photo = collect($ctx['source_images'] ?? [])->first()) {
            $refUrl = $this->replicateUpload((string) file_get_contents($photo), (new \finfo(FILEINFO_MIME_TYPE))->file($photo));
            $name = 'Your character';
        } else {
            $base = $nano->generate(trim($who) !== '' ? trim($who).'. Full body, front view, standing, plain flat cream background, centred.' : 'An original friendly mascot character. Full body, front view, plain flat cream background.', '3d', '1:1');
            $refUrl = $base['image_url'] ?? $this->replicateUpload(base64_decode((string) ($base['image_b64'] ?? '')), 'image/png');
        }
        $keep = ' Same character exactly: same body shape, colours, face, details and texture. Full body, plain flat cream background, centred, nothing else in frame.';
        $files = [];
        foreach ($poses as $i => $pose) {
            // A dropped connection mid-sheet shouldn't lose the poses already paid for.
            $r = retry(3, fn () => $nano->generate('The character from the reference image, '.$pose.'.'.$keep, '3d', '1:1', ['reference_image_url' => $refUrl]),
                3000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException);
            $src = $r['image_url'] ?? $this->replicateUpload(base64_decode((string) ($r['image_b64'] ?? '')), 'image/png');
            $cut = retry(3, fn () => $this->replicate('851-labs/background-remover', ['image' => $src, 'format' => 'png', 'background_type' => 'rgba']),
                3000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException);
            $path = $this->fetch($cut, $dir.'/pose-'.$i.'.png');
            $files[] = ['path' => $path, 'title' => $name.' · '.Str::limit($pose, 40, '…'), 'pose' => $pose];
        }
        return ['path' => $files[0]['path'], 'mime' => 'image/png', 'title' => $files[0]['title'], 'provider_id' => 'poses-'.Str::uuid(), 'extra' => array_slice($files, 1), 'poses' => array_column($files, 'pose')];
    }

    /** Upload bytes to Replicate's file store so a model can read a private image. */
    private function replicateUpload(string $bytes, string $mime): string
    {
        if ($bytes === '') throw new RuntimeException('The character image could not be read.');
        $r = Http::withToken((string) config('services.replicate.api_token'))->timeout(60)->attach('content', $bytes, 'image.'.(str_contains($mime, 'jpeg') ? 'jpg' : 'png'), ['Content-Type' => $mime])
            ->post('https://api.replicate.com/v1/files');
        $url = $r->json('urls.get');
        if (! $r->successful() || ! is_string($url)) throw new RuntimeException('The character image could not be prepared for the model.');
        return $url;
    }

    /**
     * Music models sometimes finish the song early and leave silence. Keep the
     * audible part, loop it with a crossfade until the bed covers the video,
     * and fade out over the last second.
     */
    public static function fillMusic(string $path, int $seconds, string $dir): string
    {
        $r = Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-nostats', '-i', $path, '-af', 'silencedetect=n=-40dB:d=1', '-f', 'null', '-']);
        $log = $r->errorOutput().$r->output();
        $total = (float) trim(Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output());
        $audible = $total;
        // A silence that runs to the end of the file (ffmpeg reports its end at EOF) is the early ending.
        preg_match_all('/silence_start: ([0-9.]+)/', $log, $s);
        preg_match_all('/silence_end: ([0-9.]+)/', $log, $e);
        if ($s[1] && (count($s[1]) > count($e[1]) || (float) end($e[1]) >= $total - 0.25)) $audible = (float) end($s[1]);
        if ($audible >= $seconds - 0.5 || $audible < 3) {
            $out = $dir.'/music-bed.wav';
            Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $path, '-t', (string) $seconds, '-af', 'afade=t=out:st='.max(0, $seconds - 1).':d=1', $out]);
            return is_file($out) ? $out : $path;
        }
        $body = $dir.'/music-body.wav';
        Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $path, '-t', (string) round($audible, 2), $body]);
        $chain = $body; $len = $audible; $i = 0;
        while ($len < $seconds + 1 && $i < 8) {
            $next = $dir.'/music-loop-'.(++$i).'.wav';
            Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $chain, '-i', $body, '-filter_complex', 'acrossfade=d=0.8', $next]);
            if (! is_file($next)) break;
            $chain = $next; $len += $audible - 0.8;
        }
        $out = $dir.'/music-bed.wav';
        Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $chain, '-t', (string) $seconds, '-af', 'afade=t=out:st='.max(0, $seconds - 1).':d=1', $out]);
        return is_file($out) ? $out : $path;
    }

    /**
     * One Stable Audio file holding every cue, separated by silence, then
     * mapped into cues by silence detection. One $0.20 call instead of one per cue.
     */
    private function soundSheet(string $description, string $dir): array
    {
        $names = array_values(array_slice(array_filter(array_map('trim', preg_split('/[,;\n]+/', preg_replace('/^[^:]*:\s*/', '', $description)))), 0, 6)) ?: ['soft UI click', 'quick whoosh', 'light pop'];
        $url = $this->replicate('stability-ai/stable-audio-2.5', ['prompt' => 'Sound design sheet: '.count($names).' distinct one-shot sound effects, each 0.4 to 1 second long, separated by one and a half seconds of complete silence, in this order: '.implode('; ', $names).'. Each effect is a single clear sound, not a rhythm or loop. No music, no voice.',
            'duration' => (int) min(20, count($names) * 2 + 1), 'steps' => 8]);
        $raw = $this->fetch($url, $dir.'/sfx.audio');
        $wav = $dir.'/sfx.wav';
        if (! Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $raw, '-ac', '2', '-ar', '44100', $wav])->successful()) throw new RuntimeException('The sound effects could not be prepared.');
        $r = Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-nostats', '-i', $wav, '-af', 'silencedetect=n=-40dB:d=0.25', '-f', 'null', '-']);
        preg_match_all('/silence_(start|end): ([0-9.]+)/', $r->errorOutput().$r->output(), $m, PREG_SET_ORDER);
        $d = Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $wav]);
        $total = (float) trim($d->output());
        $sounds = self::cueRanges($m, $total);
        if (! $sounds) throw new RuntimeException('No distinct sound effects were produced.');
        $cues = array_map(fn ($s, $i) => ['name' => $names[$i] ?? 'sound '.($i + 1), 'start' => $s[0], 'end' => $s[1]], array_slice($sounds, 0, 6), array_keys(array_slice($sounds, 0, 6)));
        return ['path' => $wav, 'mime' => 'audio/x-wav', 'title' => 'Sound effects · '.implode(', ', array_slice($names, 0, 3)), 'provider_id' => 'sfx-'.Str::uuid(), 'cues' => $cues];
    }

    /**
     * Sound ranges from silencedetect events: fragments closer than 0.35 s
     * merge into one cue, and specks under 0.15 s are dropped.
     *
     * @param array<int, array{0:string,1:string,2:string}> $events
     */
    public static function cueRanges(array $events, float $total): array
    {
        $raw = []; $at = 0.0; $silent = false;
        foreach ($events as $e) {
            if ($e[1] === 'start') { if ((float) $e[2] - $at > 0.01) $raw[] = [$at, (float) $e[2]]; $silent = true; }
            else { $at = (float) $e[2]; $silent = false; }
        }
        // Sound runs to the end only if the file does not finish in silence.
        if (! $silent && $total - $at > 0.01) $raw[] = [$at, $total];
        $merged = [];
        foreach ($raw as $r) {
            if ($merged && $r[0] - $merged[count($merged) - 1][1] < 0.35) $merged[count($merged) - 1][1] = $r[1];
            else $merged[] = $r;
        }
        return array_values(array_map(fn ($r) => [round($r[0], 2), round($r[1], 2)], array_filter($merged, fn ($r) => $r[1] - $r[0] >= 0.15)));
    }

    /** Run an official Replicate model and return its output URL. */
    private function replicate(string $model, array $input): string
    {
        $token = (string) config('services.replicate.api_token');
        if ($token === '') throw new RuntimeException('The media provider is not configured.');
        $http = fn () => Http::withToken($token)->acceptJson()->timeout(90);
        $res = $http()->withHeaders(['Prefer' => 'wait=60'])->post('https://api.replicate.com/v1/models/'.$model.'/predictions', ['input' => $input]);
        // Community models are run by version id, not by name.
        if ($res->status() === 404) {
            $version = $http()->get('https://api.replicate.com/v1/models/'.$model)->json('latest_version.id');
            if (! is_string($version) || $version === '') throw new RuntimeException('The '.explode('/', $model)[1].' model is not available.');
            $res = $http()->withHeaders(['Prefer' => 'wait=60'])->post('https://api.replicate.com/v1/predictions', ['version' => $version, 'input' => $input]);
        }
        $p = $res->json();
        $deadline = time() + 240;
        while (in_array($p['status'] ?? '', ['starting', 'processing'], true) && time() < $deadline) {
            sleep(2);
            $p = $http()->get('https://api.replicate.com/v1/predictions/'.($p['id'] ?? ''))->json();
        }
        if (($p['status'] ?? '') !== 'succeeded') throw new RuntimeException('The '.explode('/', $model)[1].' model did not finish: '.(($p['error'] ?? null) ? mb_substr((string) $p['error'], 0, 120) : ($p['status'] ?? 'no response')).'.');
        $out = is_array($p['output'] ?? null) ? ($p['output'][0] ?? null) : ($p['output'] ?? null);
        if (! is_string($out) || ! str_starts_with($out, 'https://')) throw new RuntimeException('The model returned no audio.');
        return $out;
    }

    /** Apply the workspace's pronunciations to spoken text only (whole words, any case). */
    public static function pronounce(string $text, int $workspaceId): string
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('create_pronunciations')) return $text;
        foreach (\Illuminate\Support\Facades\DB::table('create_pronunciations')->where('workspace_id', $workspaceId)->get() as $p) {
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($p->written, '/').'(?![\p{L}\p{N}])/iu', $p->spoken, $text);
        }
        return $text;
    }

    private function music(string $q, array $ctx, string $dir): array
    {
        $words = array_values(array_filter(preg_split('/\W+/', mb_strtolower($q)), fn ($w) => mb_strlen($w) > 2));
        $tracks = Asset::where('workspace_id', $ctx['workspace_id'])->where('asset_type', 'music')->where('status', 'active')->limit(50)->get();
        if ($tracks->isEmpty()) throw new RuntimeException('The music library is empty in this workspace.');
        $best = $tracks->sortByDesc(fn ($a) => collect($words)->sum(fn ($w) => (str_contains(mb_strtolower((string) $a->title), $w) ? 3 : 0) + (str_contains(mb_strtolower((string) $a->description), $w) ? 2 : 0)))->first();
        $url = app(StorageService::class)->isManagedUrl((string) $best->storage_url) ? app(StorageService::class)->url((string) $best->storage_url) : (string) $best->storage_url;
        $path = $this->fetch($url, $dir.'/music.mp3');
        return ['path' => $path, 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($path), 'title' => 'Music · '.Str::limit((string) $best->title, 60, '…'), 'provider_id' => 'music-'.$best->id];
    }

    /** Colours and fonts go to the agent as facts; the logo, if any, becomes a usable file. */
    private function brand(array $ctx, string $dir): array
    {
        $kit = BrandKit::where('workspace_id', $ctx['workspace_id'])->orderBy('id')->first();
        if (! $kit) throw new RuntimeException('This workspace has no brand kit yet.');
        $brand = array_filter(['name' => $kit->name, 'colors' => array_values(array_filter([$kit->primary_color, $kit->secondary_color, $kit->accent_color])),
            'fonts' => array_values(array_filter([$kit->font_primary, $kit->font_secondary]))]);
        $logo = $kit->logo_asset_id ? Asset::find($kit->logo_asset_id) : null;
        if (! $logo?->storage_url) return ['path' => '', 'mime' => '', 'title' => 'Brand kit · '.$kit->name, 'provider_id' => 'brand-'.$kit->id, 'brand' => $brand];
        $url = app(StorageService::class)->isManagedUrl((string) $logo->storage_url) ? app(StorageService::class)->url((string) $logo->storage_url) : (string) $logo->storage_url;
        $path = $this->fetch($url, $dir.'/logo.img');
        return ['path' => $path, 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($path), 'title' => 'Logo · '.$kit->name, 'provider_id' => 'brand-'.$kit->id, 'brand' => $brand];
    }

    private function fetch(string $url, string $path): string
    {
        // Our own storage (minio://, b2://): read it directly, never over HTTP.
        $storage = app(StorageService::class);
        if ($storage->isManagedUrl($url)) {
            $bytes = $storage->get($url);
            if (! is_string($bytes) || $bytes === '') throw new RuntimeException('The media file could not be read from storage.');
            file_put_contents($path, $bytes);
            if (filesize($path) > (int) config('create.input_file_bytes')) throw new RuntimeException('The media file is larger than 100 MB.');
            return $path;
        }
        abort_unless(str_starts_with($url, 'https://') || app()->environment(['local', 'testing']), 422, 'Media must come from a secure address.');
        $r = Http::timeout(120)->get($url);
        if (! $r->successful() || strlen($r->body()) === 0) throw new RuntimeException('The media file could not be downloaded.');
        file_put_contents($path, $r->body());
        if (filesize($path) > (int) config('create.input_file_bytes')) throw new RuntimeException('The media file is larger than 100 MB.');
        return $path;
    }
}
