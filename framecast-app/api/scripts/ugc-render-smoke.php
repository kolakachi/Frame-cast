<?php

use App\Models\Project;
use App\Models\Scene;
use App\Services\Ugc\UgcHeadline;
use App\Traits\RendersExportScenes;
use Illuminate\Contracts\Console\Kernel;

// Provider-free smoke fixture: invoke with an existing empty temporary directory.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$dir = realpath($argv[1] ?? '');
if (! $dir || ! is_dir($dir) || count(scandir($dir)) !== 2) {
    throw new RuntimeException('Supply an existing empty temporary directory.');
}
$headline = UgcHeadline::layout("POV: you almost gave up\nThen something clicked");
$renderer = new class
{
    use RendersExportScenes;

    public function run(string $dir, array $headline): string
    {
        $project = new Project(['aspect_ratio' => '9:16']);
        $scene = new Scene(['duration_seconds' => 1, 'visual_type' => 'ai_image',
            'script_text' => '', 'caption_settings_json' => ['enabled' => false, 'ugc_headline' => $headline]]);

        return $this->renderSceneSegment($project, $scene, null, null, null,
            ['width' => 1080, 'height' => 1920], $dir, 0, 0, 1);
    }
};
echo json_encode(['video' => $renderer->run($dir, $headline), 'layout' => $headline], JSON_THROW_ON_ERROR);
