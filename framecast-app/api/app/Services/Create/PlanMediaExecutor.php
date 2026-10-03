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
    /** Kinds this executor can make; the catalogue must not offer anything outside it. */
    public const KINDS = ['stock_video', 'stock_image', 'ai_image', 'animate_image', 'voiceover', 'cloned_voiceover', 'music', 'sfx', 'character_poses', 'character_variants', 'talking_shot', 'talking_take', 'brand_kit'];

    /** @return array{path:string,mime:string,title:string,provider_id:string,note?:string,brand?:array} */
    public function produce(string $kind, string $description, array $ctx, string $dir): array
    {
        if (in_array($kind, ['ai_image', 'animate_image', 'music', 'sfx', 'character_poses', 'character_variants'], true)) $description .= RequirementContract::prompt($ctx['task_requirements'] ?? []);
        $portrait = in_array($ctx['aspect_ratio'] ?? '9:16', ['9:16', '4:5'], true);
        return match ($kind) {
            'stock_video', 'stock_image' => $this->stock($kind, $description, $portrait, $dir),
            'ai_image' => $this->aiImage($description, $ctx, $dir),
            'animate_image' => $this->animate($description, $ctx, $dir),
            'voiceover', 'cloned_voiceover' => $this->voice($kind, $ctx, $dir),
            'library_music' => $this->music($description, $ctx, $dir),
            'music' => $this->generatedMusic($description, $ctx, $dir),
            'sfx' => $this->soundSheet($description, $dir),
            'character_poses' => $this->characterMaster($description, $ctx, $dir),
            'character_variants' => $this->characterVariants($description, $ctx, $dir),
            'talking_shot' => $this->talkingShot($ctx, $dir),
            'talking_take' => $this->talkingShot($ctx, $dir, true),
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

    public static function animationSource(array $ctx): ?string
    {
        if (($ctx['animation_subject'] ?? '') !== 'approved_character') return collect($ctx['source_images'] ?? [])->first();
        $files = $ctx['approved_character_files'] ?? [];
        if (count($files) !== 1) throw new RuntimeException('Character animation requires exactly one approved master.');
        app(InputSnapshotService::class)->verify($files);
        return \Illuminate\Support\Facades\Storage::disk('local')->path($files[0]['storage_path']);
    }

    /** Character motion uses the verified approved master; generic motion uses a source photo. */
    private function animate(string $prompt, array $ctx, string $dir): array
    {
        $photo = self::animationSource($ctx);
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

    public static function characterTreatment(string $style): string
    {
        return trim($style) !== ''
            ? 'Keep the reference character recognisable: identity, distinctive features and outfit. Redraw the character itself in this requested treatment: '.$style.'. This changes the rendering and texture, not the identity. Do not return the original photo with a dotted overlay or place the treatment only on the background.'
            : 'Same character exactly: same body shape, colours, face, details and texture.';
    }

    public const DEFAULT_POSES = ['talking, mid-sentence, friendly', 'waving hello', 'pointing to one side', 'surprised', 'thumbs up'];

    public static function requestedPoses(string $description): array
    {
        [, $list] = array_pad(explode(':', $description, 2), 2, '');
        return array_values(array_slice(array_filter(array_map('trim', preg_split('/[,;\n]+/', $list))), 0, 5)) ?: self::DEFAULT_POSES;
    }

    /** One style decision, one image. The storyboard reuses it until the user approves. */
    private function characterMaster(string $description, array $ctx, string $dir): array
    {
        [$who] = explode(':', $description, 2);
        $bust = (bool) preg_match('/\b(bust|shoulders up|head and shoulders|chest up|presenter)\b/i', $description);
        $framing = $bust ? 'Bust shot from the chest up, front view, head and shoulders filling the frame' : 'Full body, front view';
        $refs = []; $name = 'Character';
        $saved = \App\Models\Character::where('workspace_id', $ctx['workspace_id'])->where('status', '!=', 'archived')->get()
            ->first(fn ($c) => $c->name && str_contains(mb_strtolower($description), mb_strtolower($c->name)));
        $refAsset = $saved ? Asset::where('workspace_id', $ctx['workspace_id'])->where('status', '!=', 'archived')->find($saved->reference_asset_id ?: $saved->preview_asset_id) : null;
        if ($refAsset?->storage_url) {
            $refs[] = $this->replicateUpload((string) app(StorageService::class)->get((string) $refAsset->storage_url), $refAsset->mime_type ?: 'image/png');
            $name = $saved->name;
        } elseif ($photo = collect($ctx['source_images'] ?? [])->first()) {
            $refs[] = $this->replicateUpload((string) file_get_contents($photo), (new \finfo(FILEINFO_MIME_TYPE))->file($photo));
            $name = 'Your character';
        }
        $hasIdentity = count($refs) > 0;
        foreach (array_slice($ctx['character_style_images'] ?? [], 0, 3) as $image) {
            $refs[] = $this->replicateUpload((string) file_get_contents($image), (new \finfo(FILEINFO_MIME_TYPE))->file($image));
        }
        $style = trim((string) ($ctx['character_style'] ?? ''));
        $roles = $hasIdentity
            ? 'Image 1 supplies identity only: preserve its recognisable face, hair and outfit. Any later images are STYLE REFERENCES ONLY: match their character proportions, surface treatment, dot scale, shading and edge language, not their person, mascot, text or branding. '
            : 'Create the original character described below. All supplied images are STYLE REFERENCES ONLY: borrow their rendering treatment, not their character identity, text or branding. ';
        $treatment = $hasIdentity ? self::characterTreatment($style) : 'Use this rendering treatment for the original character: '.($style ?: 'the visual treatment in the style references').'.';
        $prompt = $roles.'Character: '.trim($who).'. '.$treatment.' '.$framing.'. Neutral friendly expression, one character only, plain flat cream background, no contact sheet. Establish ONE consistent master design; do not blend photographic, cartoon and comic treatments.';
        $r = app(\App\Services\Generation\Image\NanoBananaProImageAdapter::class)->generate($prompt, $style ?: '3d', '1:1', ['reference_image_urls' => $refs]);
        $src = $r['image_url'] ?? $this->replicateUpload(base64_decode((string) ($r['image_b64'] ?? '')), 'image/png');
        $cut = $this->replicate('851-labs/background-remover', ['image' => $src, 'format' => 'png', 'background_type' => 'rgba']);
        return ['path' => $this->fetch($cut, $dir.'/master.png'), 'mime' => 'image/png', 'title' => $name.' · character preview',
            'provider_id' => 'master-'.Str::uuid(), 'extra' => [], 'poses' => ['approved design preview'], 'character_contract' => CharacterApproval::CONTRACT];
    }

    /** Every pose starts from the exact approved stylized master, never the original photograph or a preceding pose. */
    private function characterVariants(string $description, array $ctx, string $dir): array
    {
        $master = $ctx['approved_character_files'][0] ?? null;
        if (! $master) throw new RuntimeException('Approve the character preview before generating poses.');
        app(InputSnapshotService::class)->verify([$master]);
        $refUrl = $this->replicateUpload(\Illuminate\Support\Facades\Storage::disk('local')->get($master['storage_path']), $master['mime_type']);
        $files = []; $poses = self::requestedPoses($description);
        foreach ($poses as $i => $pose) {
            $prompt = 'Edit the approved character in image 1 into this pose/expression: '.$pose.'. Keep the EXACT same character design: face, eye/head proportions, hair, outfit, body proportions, crop, palette, lighting, shading, halftone/dither dot scale and edge treatment. Change only pose/expression. Do not restyle or return a photographic alternative. One character, plain flat cream background.';
            $r = app(\App\Services\Generation\Image\NanoBananaProImageAdapter::class)->generate($prompt, 'approved character design', '1:1', ['reference_image_url' => $refUrl]);
            $src = $r['image_url'] ?? $this->replicateUpload(base64_decode((string) ($r['image_b64'] ?? '')), 'image/png');
            $cut = $this->replicate('851-labs/background-remover', ['image' => $src, 'format' => 'png', 'background_type' => 'rgba']);
            $files[] = ['path' => $this->fetch($cut, $dir.'/pose-'.$i.'.png'), 'title' => 'Character · '.Str::limit($pose, 40, '…'), 'pose' => $pose];
        }
        return ['path' => $files[0]['path'], 'mime' => 'image/png', 'title' => $files[0]['title'], 'provider_id' => 'poses-'.Str::uuid(),
            'extra' => array_slice($files, 1), 'poses' => $poses, 'character_contract' => CharacterApproval::CONTRACT, 'master_sha256' => $master['sha256']];
    }

    /**
     * The character lip-syncing the first narration line, for the hook: its talking
     * pose plus that line cut from the bought narration (so the clip and the voice
     * share one timeline), through the workspace's lipsync engine (VEED Fabric).
     */
    private function talkingShot(array $ctx, string $dir, bool $whole = false): array
    {
        // The take speaks the whole narration (at most 15 s, the catalogue price); the shot speaks the first line.
        $line = $whole ? trim(implode(' ', array_map('strval', (array) ($ctx['narration'] ?? [])))) : trim((string) (($ctx['narration'] ?? [])[0] ?? ''));
        if ($line === '' || empty($ctx['plan_id'])) throw new RuntimeException('A talking shot needs a narration script.');
        $item = fn (array $kinds) => \Illuminate\Support\Facades\DB::table('create_plan_media')->where('plan_id', $ctx['plan_id'])->whereIn('kind', $kinds)->where('status', 'succeeded')->first();
        $route = $ctx['talking_route'] ?? TalkingPresenter::route($whole ? 'talking_take' : 'talking_shot', $ctx['voice'] ?? null, (int) ($ctx['duration_seconds'] ?? 15));
        $native = ($route['speech_mode'] ?? null) === 'native';
        $poses = ! empty($ctx['approved_character_media_id']) ? \Illuminate\Support\Facades\DB::table('create_plan_media')->where('plan_id', $ctx['plan_id'])->where('id', $ctx['approved_character_media_id'])->where('status', 'succeeded')->first() : null; $voice = $native ? null : $item(($ctx['voice'] ?? null) === 'clone' ? ['cloned_voiceover'] : ['voiceover', 'cloned_voiceover']);
        if (! $poses || (! $native && ! $voice)) throw new RuntimeException('A talking shot needs the character poses and the narration first.');
        $p = json_decode((string) $poses->record_json, true) ?: []; $v = json_decode((string) $voice?->record_json, true) ?: [];
        $files = array_values(array_filter([$p['file'] ?? null, ...(array) ($p['more_files'] ?? [])]));
        if (! $files || (! $native && empty($v['file']))) throw new RuntimeException('The character poses or the narration are missing.');
        $i = 0; foreach ((array) ($p['poses'] ?? []) as $k => $name) if (preg_match('/talk|speak|say/i', (string) $name) && isset($files[$k])) { $i = $k; break; }
        $storage = app(StorageService::class);
        $read = function (array $f) use ($storage): array {
            $a = Asset::find((int) ($f['asset_id'] ?? 0));
            $bytes = $a?->storage_url ? $storage->get((string) $a->storage_url) : null;
            if (! is_string($bytes) || $bytes === '') throw new RuntimeException('A bought file could not be read.');
            return [$bytes, (string) ($a->mime_type ?: 'application/octet-stream')];
        };
        if (! empty($ctx['approved_character_files'])) {
            $frozen = collect($ctx['approved_character_files'])->firstWhere('asset_id', $files[$i]['asset_id']);
            if (! $frozen) throw new RuntimeException('The talking pose is not one of the approved images.');
            app(InputSnapshotService::class)->verify([$frozen]);
            $image = \Illuminate\Support\Facades\Storage::disk('local')->get($frozen['storage_path']); $imageMime = $frozen['mime_type'];
        } else [$image, $imageMime] = $read($files[$i]);
        if ($native) return $this->nativeTalking($line, $image, $imageMime, $ctx, $route, $dir, $whole);
        [$audio, $audioMime] = $read($v['file']);
        $narration = $dir.'/narration.'.(str_contains($audioMime, 'mpeg') || str_contains($audioMime, 'mp3') ? 'mp3' : 'wav');
        file_put_contents($narration, $audio);
        $end = $whole ? min((float) ($route['seconds'] ?? 15), max(1.5, round((float) trim(Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $narration])->output()), 2))) : $this->firstLineEnd($narration, $audioMime, $line);
        Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $narration, '-t', (string) $end, '-c:a', 'pcm_s16le', $dir.'/line.wav']);
        if (! is_file($dir.'/line.wav')) throw new RuntimeException('The first line could not be cut from the narration.');
        $adapter = app(\App\Services\Generation\Video\ReplicateFabricAdapter::class);
        $id = $adapter->start($this->replicateUpload($image, $imageMime), $this->replicateUpload((string) file_get_contents($dir.'/line.wav'), 'audio/wav'), null);
        // Measured: a 3 s shot returns in about a minute; a 15 s take in five to ten.
        $url = $adapter->pollUntilDone($id, $whole ? 840 : 330);
        if (! $url) throw new RuntimeException(($whole ? 'The talking take' : 'The talking shot').' took too long to make.');
        $path = $this->fetch($url, $dir.'/talking.mp4');
        return ['path' => $path, 'mime' => 'video/mp4', 'title' => ($whole ? 'Talking take · ' : 'Talking shot · ').Str::limit($line, 40, '…'), 'provider_id' => 'talk-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $id), 'line' => $line, 'seconds' => $end];
    }

    private function nativeTalking(string $line, string $image, string $mime, array $ctx, array $route, string $dir, bool $whole): array
    {
        $adapter = app(\App\Services\Generation\Video\ReplicateVeoAdapter::class);
        $spoken = self::pronounce($line, (int) $ctx['workspace_id']);
        $prompt = 'Animate the supplied character as a talking presenter. Preserve its identity, styling, texture and clothing, including halftone or illustration treatment if present. '
            .'One continuous performance, natural gestures, clear synchronized native speech. Speak only this approved script in '.($ctx['language'] ?? 'en').': '
            .json_encode($spoken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'. Complete every word. No additional dialogue, music, subtitles, logos or on-screen text. Keep the character clearly framed.';
        $prompt .= RequirementContract::prompt($ctx['task_requirements'] ?? []);
        $id = $adapter->start($prompt, $route['seconds'], $this->replicateUpload($image, $mime), $route['engine'], [], null, '720p', [], $ctx['aspect_ratio'] ?? '9:16');
        $url = $adapter->pollUntilDone($id, 840);
        if (! $url) throw new RuntimeException('The native talking video did not finish.');
        $path = $this->fetch($url, $dir.'/talking.mp4');
        $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type:format=duration', '-of', 'json', $path]);
        $meta = json_decode($probe->output(), true) ?: [];
        if (! $probe->successful() || ! collect($meta['streams'] ?? [])->contains('codec_type', 'audio')
            || ! collect($meta['streams'] ?? [])->contains('codec_type', 'video')) throw new RuntimeException('The native talking result is missing its video or audio track.');
        return ['path' => $path, 'mime' => 'video/mp4', 'title' => ($whole ? 'Talking take · ' : 'Talking shot · ').Str::limit($line, 40, '…'),
            'provider_id' => $id, 'line' => $line, 'seconds' => (float) ($meta['format']['duration'] ?? 0), 'speech_mode' => 'native', 'engine' => $route['engine']];
    }

    /** Where the first script line ends in the narration, from word timings; a sensible length when unsure. */
    public function firstLineEnd(string $path, string $mime, string $line): float
    {
        $norm = fn ($t) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $t));
        $tokens = array_values(array_filter(array_map($norm, preg_split('/\s+/', $line))));
        $end = null;
        try {
            $r = app(\App\Services\Media\MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($path, $mime);
            $words = ($r['provider_key'] ?? '') === 'local_fallback' ? [] : (array) ($r['words'] ?? []);
            $j = 0; $matched = 0;
            foreach ($words as $w) {
                if ($j < count($tokens) && $norm($w['text'] ?? '') === $tokens[$j]) { $j++; $matched++; $end = (float) ($w['end'] ?? 0); if ($j === count($tokens)) break; }
            }
            if ($matched < max(1, (int) ceil(count($tokens) * 0.6))) $end = null;
        } catch (\Throwable) { $end = null; }
        return round(max(1.5, min(4.5, ($end ?? 2.8) + 0.2)), 2);
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
