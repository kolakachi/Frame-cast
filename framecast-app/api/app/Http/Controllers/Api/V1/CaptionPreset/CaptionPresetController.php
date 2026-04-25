<?php

namespace App\Http\Controllers\Api\V1\CaptionPreset;

use App\Http\Controllers\Controller;
use App\Models\CaptionPreset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaptionPresetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $presets = CaptionPreset::query()
            ->where('workspace_id', $user->workspace_id)
            ->orderBy('name')
            ->get()
            ->map(fn (CaptionPreset $p): array => $this->serialize($p))
            ->values()
            ->all();

        return response()->json(['data' => ['caption_presets' => $presets], 'meta' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:80'],
            'preset_type'      => ['nullable', 'string', 'in:impact,editorial,hacker'],
            'font'             => ['nullable', 'string', 'max:100'],
            'font_size_rule'   => ['nullable', 'string', 'in:small,medium,large,xlarge'],
            'highlight_mode'   => ['nullable', 'string', 'in:keywords,word_by_word,line_by_line,none'],
            'highlight_color'  => ['nullable', 'string', 'max:20'],
            'caption_color'    => ['nullable', 'string', 'max:20'],
            'caption_position' => ['nullable', 'string', 'in:bottom_third,center,top_third'],
        ]);

        $preset = CaptionPreset::query()->create([
            'workspace_id'     => $user->workspace_id,
            'name'             => $validated['name'],
            'preset_type'      => $validated['preset_type'] ?? null,
            'font'             => $validated['font'] ?? null,
            'font_size_rule'   => $validated['font_size_rule'] ?? null,
            'highlight_mode'   => $validated['highlight_mode'] ?? null,
            'highlight_color'  => $validated['highlight_color'] ?? null,
            'caption_color'    => $validated['caption_color'] ?? null,
            'caption_position' => $validated['caption_position'] ?? null,
        ]);

        return response()->json(['data' => ['caption_preset' => $this->serialize($preset)], 'meta' => []], 201);
    }

    public function destroy(Request $request, int $presetId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preset = CaptionPreset::query()
            ->where('workspace_id', $user->workspace_id)
            ->find($presetId);

        if (! $preset) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Preset not found.']], 404);
        }

        $preset->delete();

        return response()->json(['data' => ['deleted' => true], 'meta' => []]);
    }

    /** @return array<string, mixed> */
    private function serialize(CaptionPreset $preset): array
    {
        return [
            'id'               => $preset->getKey(),
            'name'             => $preset->name,
            'preset_type'      => $preset->preset_type,
            'font'             => $preset->font,
            'font_size_rule'   => $preset->font_size_rule,
            'highlight_mode'   => $preset->highlight_mode,
            'highlight_color'  => $preset->highlight_color,
            'caption_color'    => $preset->caption_color,
            'caption_position' => $preset->caption_position,
            'created_at'       => $preset->created_at?->toISOString(),
        ];
    }
}
