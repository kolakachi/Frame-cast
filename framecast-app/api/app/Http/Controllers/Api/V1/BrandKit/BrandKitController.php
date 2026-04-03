<?php

namespace App\Http\Controllers\Api\V1\BrandKit;

use App\Http\Controllers\Controller;
use App\Models\BrandKit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandKitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $brandKits = BrandKit::query()
            ->where('workspace_id', $user->workspace_id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'brand_kits' => $brandKits->map(fn (BrandKit $brandKit): array => $this->serializeBrandKit($brandKit))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->workspace_id) {
            return $this->error('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        $validated = $request->validate($this->rules());

        $brandKit = BrandKit::query()->create([
            ...$validated,
            'workspace_id' => $user->workspace_id,
        ]);

        return response()->json([
            'data' => [
                'brand_kit' => $this->serializeBrandKit($brandKit),
            ],
            'meta' => [],
        ], 201);
    }

    public function show(Request $request, int $brandKitId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $brandKit = $this->findForUser($user, $brandKitId);

        if (! $brandKit) {
            return $this->error('not_found', 'Brand kit not found.', 404);
        }

        return response()->json([
            'data' => [
                'brand_kit' => $this->serializeBrandKit($brandKit),
            ],
            'meta' => [],
        ]);
    }

    public function update(Request $request, int $brandKitId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $brandKit = $this->findForUser($user, $brandKitId);

        if (! $brandKit) {
            return $this->error('not_found', 'Brand kit not found.', 404);
        }

        $validated = $request->validate($this->rules(true));
        $brandKit->fill($validated)->save();

        return response()->json([
            'data' => [
                'brand_kit' => $this->serializeBrandKit($brandKit->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $brandKitId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $brandKit = $this->findForUser($user, $brandKitId);

        if (! $brandKit) {
            return $this->error('not_found', 'Brand kit not found.', 404);
        }

        $brandKit->delete();

        return response()->json([
            'data' => [
                'deleted' => true,
            ],
            'meta' => [],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $nullable = $partial ? 'sometimes' : 'nullable';

        return [
            'name' => [$required, 'string', 'max:255'],
            'primary_color' => [$nullable, 'nullable', 'string', 'max:32'],
            'secondary_color' => [$nullable, 'nullable', 'string', 'max:32'],
            'accent_color' => [$nullable, 'nullable', 'string', 'max:32'],
            'font_primary' => [$nullable, 'nullable', 'string', 'max:255'],
            'font_secondary' => [$nullable, 'nullable', 'string', 'max:255'],
            'logo_asset_id' => [$nullable, 'nullable', 'integer'],
            'default_caption_style' => [$nullable, 'nullable', 'string', 'max:255'],
            'default_voice_profile_id' => [$nullable, 'nullable', 'integer'],
        ];
    }

    private function findForUser(User $user, int $brandKitId): ?BrandKit
    {
        return BrandKit::query()
            ->whereKey($brandKitId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    /**
     * @return array{
     *     id:int,
     *     workspace_id:int,
     *     name:string,
     *     primary_color:?string,
     *     secondary_color:?string,
     *     accent_color:?string,
     *     font_primary:?string,
     *     font_secondary:?string,
     *     logo_asset_id:?int,
     *     default_caption_style:?string,
     *     default_voice_profile_id:?int,
     *     created_at:?string,
     *     updated_at:?string
     * }
     */
    private function serializeBrandKit(BrandKit $brandKit): array
    {
        return [
            'id' => $brandKit->getKey(),
            'workspace_id' => $brandKit->workspace_id,
            'name' => $brandKit->name,
            'primary_color' => $brandKit->primary_color,
            'secondary_color' => $brandKit->secondary_color,
            'accent_color' => $brandKit->accent_color,
            'font_primary' => $brandKit->font_primary,
            'font_secondary' => $brandKit->font_secondary,
            'logo_asset_id' => $brandKit->logo_asset_id,
            'default_caption_style' => $brandKit->default_caption_style,
            'default_voice_profile_id' => $brandKit->default_voice_profile_id,
            'created_at' => $brandKit->created_at?->toIso8601String(),
            'updated_at' => $brandKit->updated_at?->toIso8601String(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
