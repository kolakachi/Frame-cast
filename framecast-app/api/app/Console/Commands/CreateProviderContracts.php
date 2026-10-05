<?php

namespace App\Console\Commands;

use App\Services\Create\ShotRoute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Http, Log, Storage};

/**
 * The provider contract check (docs/product/create-verify-and-teach-scope.md, 1c): every model Create sends work to
 * publishes its input schema on Replicate. This compares that schema with what our code sends (parameter names, the
 * values we rely on, the inputs the model requires) and reports any difference before a user's run meets it. Free:
 * it only reads model pages. Rules a schema does not state (Omni's "start frame or references, not both") are covered
 * by tests and the paid canary instead.
 */
class CreateProviderContracts extends Command
{
    protected $signature = 'create:provider-contracts {--json : Print the report as JSON}';
    protected $description = 'Check that every Create provider model still accepts the inputs we send';

    /** What we send each model: input names, and the values we rely on for constrained inputs. */
    public static function contracts(): array
    {
        $seconds = fn (array $r) => isset($r['steps']) ? $r['steps'] : [$r['min'], $r['max']];
        return [
            'bytedance/seedance-2.5' => ['sends' => ['prompt', 'aspect_ratio', 'resolution', 'generate_audio', 'duration', 'watermark', 'seed', 'image', 'reference_images', 'reference_videos'],
                'values' => ['aspect_ratio' => [...ShotRoute::REF['seedance25']['aspects'], 'adaptive'], 'resolution' => ['480p', '720p'], 'duration' => $seconds(ShotRoute::REF['seedance25'])]],
            'google/gemini-omni-1.1' => ['sends' => ['prompt', 'aspect_ratio', 'resolution', 'image', 'reference_images'],
                'values' => ['aspect_ratio' => ShotRoute::REF['omni']['aspects'], 'resolution' => ['720p']]],
            'google/veo-3.1' => ['sends' => ['prompt', 'aspect_ratio', 'resolution', 'generate_audio', 'duration', 'image', 'reference_images'],
                'values' => ['aspect_ratio' => ShotRoute::REF['veo_hq']['aspects'], 'resolution' => ['720p'], 'duration' => $seconds(ShotRoute::REF['veo_hq'])]],
            'google/veo-3.1-fast' => ['sends' => ['prompt', 'aspect_ratio', 'resolution', 'generate_audio', 'duration', 'image'],
                'values' => ['aspect_ratio' => ['16:9', '9:16'], 'resolution' => ['720p'], 'duration' => [4, 6, 8]]],
            'elevenlabs/music' => ['sends' => ['prompt', 'music_length_ms', 'force_instrumental', 'output_format'], 'values' => ['output_format' => ['mp3_high_quality']]],
            'stability-ai/stable-audio-2.5' => ['sends' => ['prompt', 'duration', 'steps'], 'values' => []],
            '851-labs/background-remover' => ['sends' => ['image', 'format', 'background_type'], 'values' => ['format' => ['png'], 'background_type' => ['rgba']]],
            // First-frame engines run through the main app's adapter: their availability is what is checked here.
            (string) config('services.replicate.i2v_quick_model') => ['sends' => [], 'values' => []],
            (string) config('services.replicate.i2v_balanced_model') => ['sends' => [], 'values' => []],
            (string) config('services.replicate.i2v_premium_model') => ['sends' => [], 'values' => []],
            (string) config('services.replicate.i2v_seedance_lite_model') => ['sends' => [], 'values' => []],
            (string) config('services.replicate.i2v_seedance_pro_model') => ['sends' => [], 'values' => []],
        ];
    }

    /**
     * Differences between a model's input schema and what we send: missing model, unknown inputs, required inputs we
     * do not send, values the schema does not allow. $schema is the model's openapi_schema.
     */
    public static function differences(?array $schema, array $contract): array
    {
        if (! $schema) return ['The model or its schema could not be read.'];
        $input = data_get($schema, 'components.schemas.Input', []);
        $props = (array) ($input['properties'] ?? []);
        $out = [];
        foreach ($contract['sends'] as $name) if (! array_key_exists($name, $props)) $out[] = "We send '$name', which the model no longer takes.";
        foreach ((array) ($input['required'] ?? []) as $name) if ($contract['sends'] && ! in_array($name, $contract['sends'], true)) $out[] = "The model now requires '$name', which we do not send.";
        foreach ($contract['values'] as $name => $values) {
            $p = $props[$name] ?? null;
            if (! $p) continue;
            // An allowed list may sit on the input itself or behind a reference to a shared schema.
            $ref = data_get($p, 'allOf.0.$ref') ?? ($p['$ref'] ?? null);
            $def = $ref ? data_get($schema, str_replace('/', '.', ltrim(substr($ref, 1), '/'))) : $p;
            $enum = $def['enum'] ?? null;
            foreach ($values as $v) {
                if (is_array($enum) && ! in_array($v, $enum, false)) $out[] = "'$name' no longer allows ".json_encode($v).' (allowed: '.implode(', ', array_map('json_encode', $enum)).').';
                if (is_numeric($v) && isset($def['minimum']) && $v < $def['minimum']) $out[] = "'$name' minimum is now {$def['minimum']}; we send $v.";
                if (is_numeric($v) && isset($def['maximum']) && $v > $def['maximum']) $out[] = "'$name' maximum is now {$def['maximum']}; we send $v.";
            }
        }
        return $out;
    }

    public function handle(): int
    {
        $token = (string) config('services.replicate.api_token');
        if ($token === '') { $this->error('Replicate is not configured.'); return self::FAILURE; }
        $report = ['checked_at' => now()->toIso8601String(), 'models' => []];
        foreach (self::contracts() as $model => $contract) {
            if ($model === '') continue;
            $r = rescue(fn () => Http::withToken($token)->acceptJson()->timeout(30)->retry(2, 2000, throw: false)->get('https://api.replicate.com/v1/models/'.$model), null, false);
            $schema = $r?->successful() ? $r->json('latest_version.openapi_schema') : null;
            $report['models'][$model] = ['version' => $r?->json('latest_version.id'), 'differences' => self::differences($schema, $contract)];
        }
        // What changed since the last check (a new model version is worth knowing even when nothing broke).
        $previous = json_decode((string) Storage::disk('local')->get('create/provider-contracts.json'), true) ?: [];
        foreach ($report['models'] as $model => &$m) $m['new_version'] = isset($previous['models'][$model]) && ($previous['models'][$model]['version'] ?? null) !== $m['version'];
        unset($m);
        Storage::disk('local')->put('create/provider-contracts.json', json_encode($report, JSON_PRETTY_PRINT));
        $broken = array_filter($report['models'], fn ($m) => $m['differences']);
        if ($broken) Log::warning('Create provider contracts changed', ['models' => array_map(fn ($m) => $m['differences'], $broken)]);
        if ($this->option('json')) { $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return $broken ? self::FAILURE : self::SUCCESS; }
        foreach ($report['models'] as $model => $m) {
            $this->line(sprintf('%-34s %s%s', $model, $m['differences'] ? 'CHANGED' : 'ok', $m['new_version'] ? ' (new version)' : ''));
            foreach ($m['differences'] as $d) $this->line('    - '.$d);
        }
        $this->info(count($broken) ? count($broken).' model(s) changed.' : 'All provider contracts hold.');
        return $broken ? self::FAILURE : self::SUCCESS;
    }
}
