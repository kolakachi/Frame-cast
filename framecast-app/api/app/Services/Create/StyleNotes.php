<?php
namespace App\Services\Create;

use App\Models\User;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * What the user said about finished videos, per style, so the next build in
 * that style starts from it (the "update the skill with what I liked" loop).
 */
class StyleNotes
{
    public const LIMIT = 10;

    /** The style key a run was built in: pack:<slug>, saved:<id>, reference or free. */
    public static function keyFor(array $input): string
    {
        $pack = $input['style_pack'] ?? null;
        if (is_array($pack)) {
            if (($pack['route'] ?? '') === 'pack' && ! empty($pack['slug'])) return 'pack:'.$pack['slug'];
            if (($pack['route'] ?? '') === 'saved' && ! empty($input['settings']['style_id'])) return 'saved:'.$input['settings']['style_id'];
            if (($pack['route'] ?? '') === 'reference') return 'reference';
        }
        return 'free';
    }

    public function add(User $user, string $conversationId, string $revisionId, string $note): array
    {
        app(ConversationService::class)->authorize($user, true);
        $c = app(ConversationService::class)->conversation($user, $conversationId);
        $rev = DB::table('composition_revisions')->where('conversation_id', $c->id)->where('id', $revisionId)->firstOrFail();
        $run = $rev->run_id ? DB::table('composition_runs')->where('id', $rev->run_id)->first() : null;
        $key = $run ? self::keyFor(json_decode($run->input_json, true) ?: []) : 'free';
        $note = Str::limit(trim($note), 400, '');
        abort_if($note === '', 422, 'Write what worked or what to change next time.');
        DB::table('create_style_notes')->insert(['workspace_id' => $user->workspace_id, 'style_key' => $key, 'revision_id' => $rev->id, 'note' => $note,
            'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        return ['style_key' => $key, 'notes' => $this->for((int) $user->workspace_id, $key)];
    }

    /** The latest notes for one style, oldest first. */
    public function for(int $workspaceId, string $key): array
    {
        if (! Schema::hasTable('create_style_notes')) return [];
        return DB::table('create_style_notes')->where('workspace_id', $workspaceId)->where('style_key', $key)->orderByDesc('id')->limit(self::LIMIT)->pluck('note')->reverse()->values()->all();
    }

    /** Every style with notes in a workspace: [key => notes]. */
    public function all(int $workspaceId): array
    {
        if (! Schema::hasTable('create_style_notes')) return [];
        $out = [];
        foreach (DB::table('create_style_notes')->where('workspace_id', $workspaceId)->orderByDesc('id')->limit(80)->get(['style_key', 'note']) as $n) {
            if (count($out[$n->style_key] ?? []) < self::LIMIT) $out[$n->style_key][] = $n->note;
        }
        return array_map(fn ($list) => array_reverse($list), $out);
    }
}
