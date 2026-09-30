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
    public const KINDS = ['stock_video', 'stock_image', 'ai_image', 'animate_image', 'voiceover', 'cloned_voiceover', 'brand_kit'];

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
