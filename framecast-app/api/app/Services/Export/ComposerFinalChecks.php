<?php

namespace App\Services\Export;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

/**
 * Checks on a finished composer video (2026-10-28), run on the rendered file before it is stored. They are notes, never
 * a reason to fail the export: the user gets the video and is told what to look at.
 *
 *  - sound: the file has an audio track when the video has narration or music;
 *  - blank: no unintended black stretches (a missing picture renders black);
 *  - words: each scene's narration was really said (its voice transcript against its script, which the voice job
 *    already made for the captions, so this costs nothing);
 *  - length: the runtime against the target's window (a fifth under to a quarter over), reported, never trimmed.
 *
 * Each check is ['id', 'label', 'status' => pass|note|skipped, 'detail'].
 */
final class ComposerFinalChecks
{
    /** @param Collection<int, Scene> $scenes */
    public static function run(string $file, Project $project, Collection $scenes): array
    {
        $checks = [];
        $voiceAssets = Asset::query()->whereIn('id', $scenes->map(fn (Scene $s) => (int) data_get($s->voice_settings_json, 'audio_asset_id'))->filter()->values())
            ->get()->keyBy('id');
        $probe = self::probe($file);

        $expectsSound = $voiceAssets->isNotEmpty() || (bool) $project->music_asset_id;
        $checks[] = $probe === null
            ? ['id' => 'sound', 'label' => 'Sound', 'status' => 'skipped', 'detail' => 'The file could not be read.']
            : ($expectsSound && ! $probe['audio']
                ? ['id' => 'sound', 'label' => 'Sound', 'status' => 'note', 'detail' => 'The video has no sound track, though it has narration or music. Export it again.']
                : ['id' => 'sound', 'label' => 'Sound', 'status' => 'pass', 'detail' => '']);

        $blanks = self::blankSpans($file);
        $checks[] = $blanks === null
            ? ['id' => 'blank', 'label' => 'No blank frames', 'status' => 'skipped', 'detail' => 'Blank frames could not be measured.']
            : ($blanks === []
                ? ['id' => 'blank', 'label' => 'No blank frames', 'status' => 'pass', 'detail' => '']
                : ['id' => 'blank', 'label' => 'No blank frames', 'status' => 'note', 'detail' => 'The screen is black at '.implode(', ', array_map(fn ($b) => self::clock($b[0]).'–'.self::clock($b[1]), array_slice($blanks, 0, 3))).'. A scene may be missing its picture.']);

        $missed = [];
        foreach ($scenes->values() as $i => $scene) {
            $script = trim((string) $scene->script_text);
            $asset = $voiceAssets->get((int) data_get($scene->voice_settings_json, 'audio_asset_id'));
            if ($script === '' || ! $asset || trim((string) $asset->transcript_text) === '') continue;
            if (self::coverage($script, (string) $asset->transcript_text) < 0.8) $missed[] = (int) ($scene->scene_order ?: $i + 1);
        }
        $checks[] = $voiceAssets->isEmpty()
            ? ['id' => 'words', 'label' => 'Every word spoken', 'status' => 'skipped', 'detail' => 'No narration.']
            : ($missed === []
                ? ['id' => 'words', 'label' => 'Every word spoken', 'status' => 'pass', 'detail' => '']
                : ['id' => 'words', 'label' => 'Every word spoken', 'status' => 'note', 'detail' => 'Some words may be missing or unclear in scene '.implode(', ', array_slice($missed, 0, 5)).'. Listen to '.(count($missed) > 1 ? 'them' : 'it').', and re-record the voice if needed.']);

        $target = (int) ($project->duration_target_seconds ?: 0);
        if ($target > 0 && $probe !== null && $probe['seconds'] > 0) {
            $s = $probe['seconds'];
            $checks[] = $s >= $target * 0.8 && $s <= $target * 1.25
                ? ['id' => 'length', 'label' => 'Length', 'status' => 'pass', 'detail' => sprintf('%d s (target %d s).', (int) round($s), $target)]
                : ['id' => 'length', 'label' => 'Length', 'status' => 'note', 'detail' => sprintf('%d s, against the %d s asked for. Nothing was cut; shorten or add a scene if you need it closer.', (int) round($s), $target)];
        }

        return $checks;
    }

    /** Share of the script's words heard in the transcript, in order (a longest common subsequence over words). */
    public static function coverage(string $script, string $heard): float
    {
        $a = self::words($script);
        $b = self::words($heard);
        if ($a === []) return 1.0;
        if ($b === []) return 0.0;
        $prev = array_fill(0, count($b) + 1, 0);
        foreach ($a as $wa) {
            $cur = [0];
            foreach ($b as $j => $wb) {
                $cur[$j + 1] = $wa === $wb ? $prev[$j] + 1 : max($prev[$j + 1], $cur[$j]);
            }
            $prev = $cur;
        }

        return end($prev) / count($a);
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $text = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '');

        return array_values(array_filter(preg_split('/\s+/u', $text) ?: [], fn ($w) => $w !== ''));
    }

    /** @return array{audio: bool, seconds: float}|null */
    private static function probe(string $file): ?array
    {
        $p = new Process(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type:format=duration', '-of', 'json', $file]);
        $p->setTimeout(60);
        $p->run();
        if (! $p->isSuccessful()) return null;
        $j = json_decode($p->getOutput(), true);
        if (! is_array($j)) return null;

        return ['audio' => collect($j['streams'] ?? [])->contains('codec_type', 'audio'), 'seconds' => (float) ($j['format']['duration'] ?? 0)];
    }

    /** Black stretches of half a second or more, as [start, end]; null when the measurement could not run. */
    private static function blankSpans(string $file): ?array
    {
        $p = new Process(['ffmpeg', '-hide_banner', '-nostats', '-i', $file, '-an', '-vf', 'blackdetect=d=0.5:pic_th=0.97:pix_th=0.08', '-f', 'null', '-']);
        $p->setTimeout(180);
        $p->run();
        if (! $p->isSuccessful()) return null;
        preg_match_all('/black_start:([\d.]+)\s+black_end:([\d.]+)/', $p->getErrorOutput(), $m, PREG_SET_ORDER);

        return array_map(fn ($x) => [(float) $x[1], (float) $x[2]], $m);
    }

    private static function clock(float $s): string
    {
        return sprintf('%d:%02d', intdiv((int) round($s), 60), (int) round($s) % 60);
    }
}
