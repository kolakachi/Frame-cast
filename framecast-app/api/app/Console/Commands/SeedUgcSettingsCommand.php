<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Media\StorageService;
use App\Services\Ugc\UgcSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Make the background photos for the UGC settings (config/ugc_settings.php) on google/imagen-4, about $0.04 each.
 * Empty places with no people: each is a loose reference for the kind of place, so it never carries a face or a brand.
 * Settings that already have a photo are skipped.
 */
class SeedUgcSettingsCommand extends Command
{
    protected $signature = 'ugc:seed-settings
        {--workspace= : Workspace the photos are stored under (default: services.ugc.settings_workspace_id)}
        {--only= : Comma-separated setting keys}
        {--dry : List what would be made without generating}';

    protected $description = 'Make the UGC setting background photos (paid: google/imagen-4 on Replicate)';

    public function handle(StorageService $storage): int
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $have = array_keys(UgcSettings::available());
        $todo = array_filter(UgcSettings::all(), fn ($s, $k) => ! in_array($k, $have, true) && (! $only || in_array($k, $only, true)), ARRAY_FILTER_USE_BOTH);
        $this->line(count($todo).' background photo(s) to make, about $'.number_format(count($todo) * 0.04, 2).'.');
        if ($this->option('dry') || ! $todo) {
            foreach ($todo as $k => $s) $this->line("  {$k}: {$s['plate']}");
            return self::SUCCESS;
        }
        $token = (string) config('services.replicate.api_token');
        foreach ($todo as $key => $s) {
            $prompt = 'Vertical phone photo of '.$s['plate'].'. Realistic, natural light, lived-in and believable, '
                .'shot at eye level as if someone is about to film a video here. No people, no text, no logos, no brand names, no watermarks.';
            $res = Http::withToken($token)->withHeaders(['Prefer' => 'wait=60'])->timeout(90)
                ->post('https://api.replicate.com/v1/models/google/imagen-4/predictions', ['input' => [
                    'prompt' => $prompt, 'aspect_ratio' => '9:16', 'output_format' => 'png', 'safety_filter_level' => 'block_only_high']]);
            $j = $res->json();
            for ($t = 0; $t < 30 && ! in_array($j['status'] ?? '', ['succeeded', 'failed', 'canceled'], true) && ! empty($j['id']); $t++) {
                sleep(3);
                $j = Http::withToken($token)->get('https://api.replicate.com/v1/predictions/'.$j['id'])->json();
            }
            if (($j['status'] ?? '') !== 'succeeded') {
                $this->error("  {$key}: ".mb_substr((string) json_encode($j['error'] ?? $j['detail'] ?? 'no image'), 0, 200));
                continue;
            }
            $url = is_array($j['output']) ? $j['output'][0] : $j['output'];
            $bytes = Http::timeout(60)->get($url)->body();
            if (strlen($bytes) < 1000) { $this->error("  {$key}: the photo did not download"); continue; }
            $path = 'ugc-settings/'.$key.'-'.bin2hex(random_bytes(4)).'.png';
            $storageUrl = $storage->put($path, $bytes, ['ContentType' => 'image/png']);
            Asset::query()->create([
                'workspace_id' => (int) ($this->option('workspace') ?: config('services.ugc.settings_workspace_id', 1)), 'asset_type' => 'image', 'title' => UgcSettings::title($key),
                'storage_url' => $storageUrl, 'mime_type' => 'image/png', 'file_size_bytes' => strlen($bytes),
                'tags' => ['ugc_setting', 'ai_generated'], 'status' => 'active',
            ]);
            $this->line("  {$key}: made");
        }
        return self::SUCCESS;
    }
}
