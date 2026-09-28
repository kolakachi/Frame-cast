<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Character;
use App\Models\CharacterImageGeneration;
use App\Services\Media\StorageService;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An identity sheet: the same character from four angles, generated in order
 * so each one can see the ones before it.
 *
 * Consistency was previously a matter of wording — the planner writes a
 * character_sheet in prose and repeats it in every scene. That holds a
 * description steady, not a face. The image side already fed every stored
 * reference back into each new generation ("more angles of the same face = a
 * stronger identity lock", GenerateCharacterImageJob), but nothing ever
 * produced the angles, so in practice a character had one photo and the model
 * guessed the rest.
 *
 * The order is the point. Each angle is generated only after the previous one
 * has been attached to the character, so angle four is drawn with three views
 * of the same person in front of it. Generating the four in parallel would be
 * four times faster and would return four strangers.
 *
 * This matters most to the engines with no seed to fall back on — Gemini Omni
 * takes reference_images and nothing else, so the sheet is the only thing
 * holding a presenter together across a chained ad.
 */
class GenerateCharacterSheetJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    /**
     * Four views, described the way a photographer would frame them rather
     * than by naming a rotation in degrees, which the image models read
     * inconsistently. Back views are left out on purpose: a presenter is seen
     * from the front, and a fourth informative angle beats a fifth decorative
     * one against the eight-reference ceiling.
     */
    public const ANGLES = [
        ['key' => 'front',  'label' => 'Front',  'prompt' => 'Straight-on front view, facing the camera directly, head level, neutral relaxed expression.'],
        ['key' => 'tq_left',  'label' => '3/4 left',  'prompt' => 'Three-quarter view with the head turned towards their left, both eyes still visible, neutral relaxed expression.'],
        ['key' => 'tq_right', 'label' => '3/4 right', 'prompt' => 'Three-quarter view with the head turned towards their right, both eyes still visible, neutral relaxed expression.'],
        ['key' => 'profile',  'label' => 'Profile',   'prompt' => 'Full side profile, the head turned ninety degrees so only one side of the face is visible, neutral relaxed expression.'],
    ];

    /**
     * The first angle establishes who this is and runs on the best identity
     * model. The rest are drawn with that angle already attached as a
     * reference, so they are matching rather than inventing, and the cheaper
     * model is enough: 35 + 10 + 10 + 10 instead of four times 35.
     */
    public const LEAD_MODEL = 'nano-banana-pro';
    public const FOLLOW_MODEL = 'nano-banana';

    /** What the whole sheet costs, for the quote shown before it starts. */
    public const COST = 65;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(
        public readonly int $characterId,
        public readonly int $userId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(StorageService $storage): void
    {
        $character = Character::query()->find($this->characterId);

        if (! $character || ! $character->reference_asset_id) {
            // Without a starting photo there is no identity to hold steady,
            // and four unanchored generations would be four different people.
            return;
        }

        $assetIds = [];
        $labels   = [];

        foreach (self::ANGLES as $i => $angle) {
            $generation = CharacterImageGeneration::query()->create([
                'workspace_id'     => $character->workspace_id,
                'character_id'     => $character->getKey(),
                'user_id'          => $this->userId,
                'prompt'           => $this->promptFor($character, $angle['prompt']),
                'style'            => $character->style ?: 'photorealistic',
                'model_key'        => $i === 0 ? self::LEAD_MODEL : self::FOLLOW_MODEL,
                'aspect_ratio'     => '9:16',
                'quality'          => 'high',
                // The sheet never repoints the character's primary photo. The
                // angles join the reference list; which one leads stays the
                // user's choice.
                'set_as_reference' => false,
                'status'           => 'queued',
            ]);

            // Synchronous on purpose: the next angle has to be able to see
            // this one. GenerateCharacterImageJob appends its result to the
            // character's reference list and charges only on success, so a
            // failed angle costs nothing and the sheet carries on without it.
            try {
                GenerateCharacterImageJob::dispatchSync($generation->getKey());
            } catch (\Throwable $e) {
                Log::warning('Character sheet angle failed', [
                    'character_id' => $this->characterId,
                    'angle'        => $angle['key'],
                    'error'        => $e->getMessage(),
                ]);

                continue;
            }

            $generation->refresh();

            if ($generation->status === 'completed' && $generation->result_asset_id) {
                $assetIds[] = (int) $generation->result_asset_id;
                $labels[]   = $angle['label'];
            }

            // Re-read so the next angle is built against a character that
            // already carries this one.
            $character->refresh();
        }

        if ($assetIds === []) {
            return;
        }

        $this->composeSheet($character, $assetIds, $labels, $storage);
    }

    /**
     * The angle rides on top of the character's own description, so the model
     * is told who the person is before it is told where to put their head.
     */
    private function promptFor(Character $character, string $angle): string
    {
        $who = trim((string) $character->description) !== ''
            ? trim((string) $character->description)
            : (string) $character->name;

        return trim(
            "Identity sheet reference of {$character->name}: {$who}. {$angle} "
            .'Exactly the same person, hair, clothing and skin tone as the reference images. '
            .'Even soft studio lighting, plain neutral grey background, sharp focus, head and shoulders framing.'
        );
    }

    /**
     * One labelled image, built with ffmpeg because the API container has
     * neither GD nor Imagick. Each angle is scaled into an identical cell and
     * padded rather than cropped, so a portrait frame is never cut through the
     * face to make a grid line up.
     */
    private function composeSheet(Character $character, array $assetIds, array $labels, StorageService $storage): void
    {
        $assets = Asset::query()->whereIn('id', $assetIds)->get()->keyBy('id');
        $temps  = [];

        try {
            $inputs = [];
            foreach ($assetIds as $id) {
                $asset = $assets->get($id);
                $bytes = $asset ? $storage->get((string) $asset->storage_url) : null;
                if ($bytes === null || $bytes === '') {
                    continue;
                }
                $path = sys_get_temp_dir().'/sheet-'.Str::uuid()->toString().'.png';
                file_put_contents($path, $bytes);
                $temps[] = $path;
                $inputs[] = $path;
            }

            if ($inputs === []) {
                return;
            }

            $out = sys_get_temp_dir().'/sheet-'.Str::uuid()->toString().'.png';
            $temps[] = $out;

            $this->runFfmpeg($inputs, array_slice($labels, 0, count($inputs)), $out);

            $bytes = @file_get_contents($out);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('The identity sheet came out empty.');
            }

            $stored = $storage->put(
                sprintf('workspaces/%s/assets/character-sheets/%s.png', $character->workspace_id, Str::uuid()),
                $bytes,
            );

            Asset::query()->create([
                'workspace_id'  => $character->workspace_id,
                'asset_type'    => 'image',
                'title'         => "{$character->name} — identity sheet",
                'description'   => 'Four angles of the same character, generated in order so each matches the last.',
                'storage_url'   => $stored,
                'thumbnail_url' => $stored,
                'mime_type'     => 'image/png',
                // Tagged rather than given a column on characters: a sheet is
                // just an asset, and tagging lets a character keep every sheet
                // it has ever had instead of overwriting the last one.
                'tags'          => ['ai_generated', 'character_sheet', 'character:'.$character->getKey()],
            ]);
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
    }

    /** @param list<string> $inputs @param list<string> $labels */
    private function runFfmpeg(array $inputs, array $labels, string $out): void
    {
        $n    = count($inputs);
        $font = base_path('resources/fonts/Nunito-Regular.ttf');
        $cell = ['w' => 540, 'h' => 960];
        $bg   = '0x101014';

        $args = ['ffmpeg', '-y', '-v', 'error'];
        foreach ($inputs as $in) {
            $args[] = '-i';
            $args[] = $in;
        }

        $chains = [];
        foreach ($inputs as $i => $_) {
            $label = str_replace([':', "'", '\\'], ['\\:', '', ''], $labels[$i] ?? '');
            $chains[] = "[{$i}:v]scale={$cell['w']}:{$cell['h']}:force_original_aspect_ratio=decrease,"
                ."pad={$cell['w']}:{$cell['h']}:(ow-iw)/2:(oh-ih)/2:color={$bg},"
                ."drawtext=fontfile='{$font}':text='{$label}':x=(w-text_w)/2:y=h-64:fontsize=30:"
                ."fontcolor=white:box=1:boxcolor=0x000000AA:boxborderw=14[c{$i}]";
        }

        // Two columns once there are four, so the sheet stays roughly square
        // rather than becoming a strip too wide to read on a phone.
        $layout = match ($n) {
            1 => null,
            2 => '0_0|w0_0',
            3 => '0_0|w0_0|w0+w1_0',
            default => '0_0|w0_0|0_h0|w0_h0',
        };

        if ($layout === null) {
            $chains[] = '[c0]pad=iw+48:ih+48:24:24:color='.$bg.'[out]';
        } else {
            $refs = implode('', array_map(static fn ($i) => "[c{$i}]", range(0, $n - 1)));
            $chains[] = "{$refs}xstack=inputs={$n}:layout={$layout}[g]";
            $chains[] = '[g]pad=iw+48:ih+48:24:24:color='.$bg.'[out]';
        }

        $args[] = '-filter_complex';
        $args[] = implode(';', $chains);
        $args[] = '-map';
        $args[] = '[out]';
        $args[] = '-frames:v';
        $args[] = '1';
        $args[] = $out;

        $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('Could not start ffmpeg for the identity sheet.');
        }
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($proc) !== 0) {
            throw new RuntimeException('ffmpeg could not build the identity sheet: '.mb_substr($err, 0, 200));
        }
    }

    public function failed(\Throwable $exception): void
    {
        $character = Character::query()->find($this->characterId);
        $this->recordFailureTrace(
            $exception,
            'character',
            $this->characterId,
            $character->workspace_id ?? null,
            null,
        );
    }
}
