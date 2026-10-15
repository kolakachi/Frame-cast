<?php

namespace App\Services\Create;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Another concept from the same brief (owner, 2026-10-15). A new conversation that keeps the brief, the settings, the
 * files and the brief's family, and tells the director which concepts it already made so the new one differs: from a
 * plan's unused direction, or with a one-line steer. Workspace 82 re-pasted a 1,900-character brief eight times for
 * eight ideas; this is one click. Planning is charged as for any brief.
 */
final class ConceptService
{
    public function __construct(private readonly ConversationService $conversations)
    {
    }

    /** @param array{name?: string, idea?: string}|null $direction */
    public function start(User $user, string $sourceId, string $key, ?string $steer = null, ?array $direction = null): object
    {
        $this->conversations->authorize($user, true);
        $source = $this->conversations->conversation($user, $sourceId);
        $root = ($source->concept_of ?? null) ?: $source->id;
        $replay = DB::table('create_conversations')->where('workspace_id', $user->workspace_id)->where('concept_of', $root)->where('concept_key', $key)->first();
        if ($replay) return $this->conversations->conversation($user, $replay->id);

        $brief = trim((string) DB::table('create_messages')->where('conversation_id', $root)->where('role', 'user')->orderBy('sequence')->value('content'));
        abort_if($brief === '', 422, 'This video has no brief to make another concept from.');
        $family = DB::table('create_conversations')->where('workspace_id', $user->workspace_id)
            ->where(fn ($q) => $q->where('id', $root)->orWhere('concept_of', $root))->orderBy('created_at')->pluck('id');
        $made = $family->map(function ($cid) {
            $plan = json_decode((string) DB::table('create_plans')->where('conversation_id', $cid)->orderByDesc('created_at')->value('plan_json'), true) ?: [];
            $c = (array) ($plan['concept'] ?? []);
            return trim(($c['name'] ?? '').(! empty($c['idea']) ? ': '.$c['idea'] : '')) ?: trim((string) ($plan['summary'] ?? ''));
        })->filter()->values();

        $ask = "\n\nANOTHER CONCEPT FOR THIS SAME BRIEF. Keep every rule, setting, text and fact above; change the idea."
            .($made->isNotEmpty() ? "\nConcepts already made for this brief (do not repeat or lightly vary them):\n".$made->map(fn ($m, $i) => ($i + 1).'. '.mb_substr($m, 0, 200))->implode("\n") : '')
            .(! empty($direction['name']) ? "\nMake this direction: ".mb_substr(trim($direction['name']), 0, 80).(! empty($direction['idea']) ? ' — '.mb_substr(trim($direction['idea']), 0, 300) : '') : '')
            .($steer !== null && trim($steer) !== '' ? "\nSteer: ".mb_substr(trim($steer), 0, 300) : '');

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $user, $source, $root, $key, $family) {
            $settings = json_decode((string) $source->settings_json, true) ?: [];
            unset($settings['origin_conversation_id'], $settings['origin_revision_id']);
            DB::table('create_conversations')->insert([
                'id' => $id, 'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
                'title' => mb_substr('Concept '.($family->count() + 1).': '.preg_replace('/^Concept \d+: /', '', (string) $source->title), 0, 200),
                'settings_json' => json_encode(OutputSettings::normalize($settings)), 'concept_of' => $root, 'concept_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // The brief's files come along: brand items, sources and references, with their roles.
            foreach (DB::table('create_attachments')->where('conversation_id', $source->id)->get() as $a) {
                DB::table('create_attachments')->insert(['conversation_id' => $id, 'asset_id' => $a->asset_id, 'purpose' => $a->purpose,
                    'notes_json' => $a->notes_json ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $fresh = $this->conversations->conversation($user, $id);
        $this->conversations->message($user, $id, ['content' => mb_substr($brief.$ask, 0, 10000), 'idempotency_key' => 'concept:'.$key, 'expected_version' => (int) $fresh->version]);

        return $this->conversations->conversation($user, $id);
    }
}
