<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Asset\AssetController;
use App\Models\Asset;
use App\Services\Developer\EditOperations;
use App\Services\Media\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MediaController extends DeveloperController
{
    // Reject active formats (HTML/SVG), spoofed extensions and arbitrary remote
    // URLs. MIME is detected from the uploaded bytes, never trusted from JSON.
    private const FORMATS = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'image/webp' => ['image', 'webp'],
        'audio/mpeg' => ['audio', 'mp3'], 'audio/wav' => ['audio', 'wav'], 'audio/x-wav' => ['audio', 'wav'],
        'audio/mp4' => ['audio', 'm4a'], 'audio/x-m4a' => ['audio', 'm4a'], 'audio/ogg' => ['audio', 'ogg'], 'audio/flac' => ['audio', 'flac'],
        'video/mp4' => ['video', 'mp4'], 'video/quicktime' => ['video', 'mov'], 'video/webm' => ['video', 'webm'],
    ];

    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'title' => ['required', 'string', 'max:255'],
            'asset_type' => ['required', 'in:image,audio,music,sound,video'],
            'asset_file' => ['nullable', 'file', 'max:102400'],
            'content_base64' => ['nullable', 'string', 'max:11184812'], // 8 MiB decoded
        ]);
        $file = $request->file('asset_file');
        if ((bool) $file === ! empty($input['content_base64'])) return $this->fail('invalid_upload', 'Send either asset_file (multipart) or content_base64.', 422);
        $temporary = null;
        try {
            if (! $file) {
                $bytes = base64_decode($input['content_base64'], true);
                if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 8 * 1024 * 1024) return $this->fail('invalid_upload', 'Invalid base64 or file exceeds the 8 MiB JSON limit. Multipart supports 100 MiB.', 422);
                $temporary = tempnam(sys_get_temp_dir(), 'wyv-upload-');
                file_put_contents($temporary, $bytes);
                $file = new UploadedFile($temporary, 'upload', null, null, true);
            }
            $format = self::FORMATS[$file->getMimeType()] ?? null;
            $type = in_array($input['asset_type'], ['music', 'sound'], true) ? 'audio' : $input['asset_type'];
            if (! $format || $format[0] !== $type) return $this->fail('invalid_media_type', 'File contents do not match a supported media type.', 422);
            $safeFile = new UploadedFile($file->getRealPath(), 'upload.'.$format[1], $file->getMimeType(), null, true);
            $inner = EditOperations::inner($request, ['title' => $input['title'], 'asset_type' => $input['asset_type']]);
            $inner->files->set('asset_file', $safeFile);
            return app(AssetController::class)->store($inner);
        } finally {
            if ($temporary) @unlink($temporary);
        }
    }

    public function show(Request $request, int $assetId): JsonResponse
    {
        $asset = Asset::query()->whereKey($assetId)->where('workspace_id', $request->user()->workspace_id)->first();
        if (! $asset) return $this->fail('not_found', 'Asset not found in this workspace.', 404);
        $storage = app(StorageService::class);
        return response()->json(['data' => ['asset' => [
            'id' => $asset->id, 'title' => $asset->title, 'type' => $asset->asset_type,
            'mime_type' => $asset->mime_type, 'file_size_bytes' => $asset->file_size_bytes,
            'duration_seconds' => $asset->duration_seconds,
            'url' => $storage->url((string) $asset->storage_url),
            'transcription_status' => $asset->transcription_status ?? 'not_requested',
            'transcript_text' => $asset->transcript_text,
            'transcription_error' => $asset->transcription_status === 'failed' ? 'Transcription failed; upload a clear audio sample to try again.' : null,
            'ready_for_audio_only' => $asset->asset_type === 'audio',
            'ready_for_audio_and_script' => $asset->asset_type === 'audio' && $asset->transcription_status === 'completed' && trim((string) $asset->transcript_text) !== '',
        ]], 'meta' => []]);
    }
}
