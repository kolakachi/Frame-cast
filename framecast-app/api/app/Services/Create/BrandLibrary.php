<?php
namespace App\Services\Create;

use App\Models\Asset;
use App\Models\User;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace's own brand visuals, kept once and offered to every creation: its logo, mascot, products and
 * illustrations. An item is an asset with a brand role; the brand kit's logo counts as the logo. The planner sees
 * them and uses them instead of asking again; a plan that uses one attaches it to the conversation.
 */
class BrandLibrary
{
    public const ROLES = ['logo', 'mascot', 'product', 'illustration'];

    /** @return array<int, array{asset_id:int, role:string, title:string, asset_type:string, preview_url:?string}> */
    public static function items(int $workspaceId): array
    {
        $out = [];
        foreach (Asset::where('workspace_id', $workspaceId)->where('status', '!=', 'archived')->whereNotNull('metadata_json->brand_role')->orderByDesc('updated_at')->limit(40)->get() as $a) {
            $role = (string) data_get($a->metadata_json, 'brand_role');
            if (in_array($role, self::ROLES, true)) $out[$a->id] = self::row($a, $role);
        }
        // The brand kit's logo is the logo, without saving it twice.
        if (Schema::hasTable('brand_kits')) foreach (DB::table('brand_kits')->where('workspace_id', $workspaceId)->whereNotNull('logo_asset_id')->pluck('logo_asset_id') as $id) {
            $a = Asset::where('workspace_id', $workspaceId)->where('status', '!=', 'archived')->find($id);
            if ($a && ! isset($out[$a->id])) $out[$a->id] = self::row($a, 'logo');
        }
        return array_values($out);
    }

    public static function save(User $user, int $assetId, string $role): array
    {
        abort_unless(in_array($role, self::ROLES, true), 422, 'Choose logo, mascot, product or illustration.');
        $a = Asset::where('workspace_id', $user->workspace_id)->where('status', '!=', 'archived')->findOrFail($assetId);
        abort_unless(in_array($a->asset_type, ['image', 'video'], true), 422, 'Only images and videos can be brand items.');
        $a->metadata_json = [...((array) $a->metadata_json), 'brand_role' => $role];
        $a->save();
        return self::row($a, $role);
    }

    public static function remove(User $user, int $assetId): void
    {
        $a = Asset::where('workspace_id', $user->workspace_id)->findOrFail($assetId);
        $meta = (array) $a->metadata_json; unset($meta['brand_role']);
        $a->metadata_json = $meta; $a->save();
    }

    /** Attach brand items a plan uses to its conversation, as files to use, beside the brief they answer. */
    public static function attachUsed(string $conversationId, array $assetIds, string $at): void
    {
        foreach (array_unique($assetIds) as $id) {
            if (DB::table('create_attachments')->where('conversation_id', $conversationId)->where('asset_id', $id)->exists()) continue;
            DB::table('create_attachments')->insert(['conversation_id' => $conversationId, 'asset_id' => $id, 'purpose' => 'source', 'created_at' => $at, 'updated_at' => now()]);
        }
    }

    private static function row(Asset $a, string $role): array
    {
        $url = null;
        try { $url = $a->storage_url ? app(StorageService::class)->url((string) $a->storage_url) : null; } catch (\Throwable) {}
        return ['asset_id' => (int) $a->id, 'role' => $role, 'title' => (string) $a->title, 'asset_type' => (string) $a->asset_type, 'preview_url' => $url];
    }
}
