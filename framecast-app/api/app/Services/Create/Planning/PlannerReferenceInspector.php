<?php
namespace App\Services\Create\Planning;

use App\Services\Create\InputSnapshotService;
use Illuminate\Support\Facades\{File, Storage};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Request-scoped, read-only extraction. No URLs, shell commands or provider credentials. */
class PlannerReferenceInspector
{
    private ?string $directory = null;
    private array $files = [];

    public function inspect(array $context, array $input): array
    {
        $started = microtime(true);
        if (($context['_planner_deadline'] ?? microtime(true) + 35) <= microtime(true)) throw new \RuntimeException('Reference inspection time budget exhausted.');
        $id = $input['asset_id'] ?? null;
        $allowed = collect($context['files'] ?? [])->first(fn ($f) => ($f['asset_id'] ?? null) === $id
            && ($f['purpose'] ?? null) === 'reference' && in_array($f['asset_type'] ?? '', ['video', 'image'], true));
        if (! is_int($id) || ! $allowed || empty($context['_workspace_id'])) throw new \RuntimeException('Unknown reference attachment.');
        if (array_diff(array_keys($input), ['asset_id', 'params'])) throw new \RuntimeException('Unsupported inspection arguments.');
        $disk = Storage::disk('local');
        if (! $this->directory) {
            $this->directory = $disk->path('create/planner-inspections/'.Str::uuid());
            File::makeDirectory($this->directory.'/inputs/reference', 0700, true);
        }
        if (! isset($this->files[$id])) {
            $snapshot = app(InputSnapshotService::class)->capture((int) $context['_workspace_id'], [(object) ['asset_id' => $id, 'purpose' => 'reference']]);
            try {
                $f = $snapshot[0];
                if ($f['bytes'] > 100 * 1024 * 1024) throw new \RuntimeException('Reference exceeds inspection limit.');
                File::copy(app(\App\Services\Create\CreateStorage::class)->path($f['storage_path']), $this->directory.'/inputs/reference/'.$f['name']);
                $this->files[$id] = array_diff_key($f, array_flip(['storage_path', 'transcript', 'reference'])) + ['path' => 'reference/'.$f['name']];
            } finally { app(InputSnapshotService::class)->discard($snapshot); }
            File::put($this->directory.'/inputs/manifest.json', json_encode(array_values($this->files), JSON_THROW_ON_ERROR));
        }
        $process = new Process([(string) config('create.inspection_node', 'node'), resource_path('create-reference-inspection/cli.mjs'), $this->directory]);
        $remaining = min(30, (int) floor(($context['_planner_deadline'] ?? microtime(true) + 35) - microtime(true)));
        if ($remaining < 1) throw new \RuntimeException('Reference inspection time budget exhausted.');
        $process->setInput(json_encode(['input' => $this->files[$id]['name'], 'params' => $input['params'] ?? null], JSON_THROW_ON_ERROR));
        $process->setTimeout($remaining)->mustRun();
        $evidence = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        unset($evidence['input']);
        $image = file_get_contents($this->directory.'/inspect_reference/contact-sheet.jpg');
        if (! $image || strlen($image) > 4 * 1024 * 1024) throw new \RuntimeException('Inspection image unavailable.');
        $evidence = ['asset_id' => $id, 'request' => $input['params'], 'image_sha256' => hash('sha256', $image)] + $evidence;
        // Stable across cache hits and request timing; different crops/pages/images get different IDs.
        $evidence['id'] = 'ref-'.substr(hash('sha256', json_encode([$id, $evidence['source_sha256'], $input['params'], $evidence['image_sha256']])), 0, 24);
        $evidence['inspection_elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        return ['evidence' => $evidence, 'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($image)]]];
    }

    public function close(): void
    {
        if ($this->directory) File::deleteDirectory($this->directory);
        $this->directory = null;
        $this->files = [];
    }

    public static function verifyEvidence(array $plan, array $files): void
    {
        foreach ($plan['reference_evidence'] ?? [] as $evidence) {
            $file = collect($files)->firstWhere('asset_id', $evidence['asset_id']);
            abort_unless($file && $file['purpose'] === 'reference' && hash_equals($evidence['source_sha256'], $file['sha256']),
                409, 'A studied reference changed. Plan again before creating this video.');
        }
    }
}
