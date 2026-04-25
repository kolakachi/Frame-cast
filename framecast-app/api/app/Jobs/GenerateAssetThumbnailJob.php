<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAssetThumbnailJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $timeout = 60;

    public function __construct(private readonly int $assetId) {}

    public function handle(): void
    {
        $asset = Asset::query()->find($this->assetId);

        if (! $asset) {
            return;
        }

        $label = strtoupper(str_replace('_', ' ', $asset->asset_type));
        $title = trim((string) $asset->title);
        $title = mb_substr($title !== '' ? $title : 'Framecast Asset', 0, 26);

        $palette = match ($asset->asset_type) {
            'video' => ['#102030', '#2c5364', '#60a5fa'],
            'audio' => ['#2a1a30', '#4a2a50', '#a78bfa'],
            'voice' => ['#1a1a30', '#3a2a50', '#f87171'],
            'image' => ['#1a2a20', '#2a4a30', '#34d399'],
            'template', 'scene_block' => ['#2a2a1a', '#4a4a2a', '#fbbf24'],
            default => ['#1a1a2a', '#2a2a3a', '#94a3b8'],
        };

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$palette[0]}"/>
      <stop offset="100%" stop-color="{$palette[1]}"/>
    </linearGradient>
  </defs>
  <rect width="800" height="450" rx="24" fill="url(#g)"/>
  <rect x="36" y="36" width="152" height="40" rx="12" fill="rgba(0,0,0,0.18)"/>
  <text x="54" y="62" fill="{$palette[2]}" font-family="Arial, sans-serif" font-size="22" font-weight="700">{$label}</text>
  <text x="60" y="248" fill="#ffffff" font-family="Arial, sans-serif" font-size="32" font-weight="700">{$title}</text>
  <text x="60" y="290" fill="rgba(255,255,255,0.75)" font-family="Arial, sans-serif" font-size="18">Framecast Asset Library</text>
</svg>
SVG;

        $asset->forceFill([
            'thumbnail_url' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'asset', $this->assetId);
    }
}
