<?php
namespace App\Services\Account;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes what a workspace made in Create before the workspace itself is deleted. Create's tables point at the
 * workspace, its conversations, runs and assets without cascading, so "Delete my account" failed for anyone who had
 * used Create (found 2026-10-18 deleting a test account). Credit and cost records are kept for the books.
 */
class WorkspaceEraser
{
    public const TEMPLATE_SOURCE = 'This account holds the source of a template. Contact support to close it.';

    /** Whether the workspace can be erased: not while a library template is built from one of its conversations. */
    public static function blocked(int $workspaceId): ?string
    {
        if (! Schema::hasTable('create_templates')) return null;
        // Compared as text: create_templates.conversation_id is a string, create_conversations.id a uuid on Postgres.
        $sources = DB::table('create_templates')->whereNull('archived_at')->whereNotNull('conversation_id')->pluck('conversation_id')->map(fn ($id) => (string) $id)->all();
        if (! $sources) return null;
        $owns = DB::table('create_conversations')->where('workspace_id', $workspaceId)->pluck('id')->map(fn ($id) => (string) $id)->intersect($sources)->isNotEmpty();
        return $owns ? self::TEMPLATE_SOURCE : null;
    }

    /** Deletes the workspace's Create data; call inside the transaction that deletes the workspace. */
    public function eraseCreate(Workspace $workspace): void
    {
        $ws = (int) $workspace->getKey();
        $conversations = DB::table('create_conversations')->where('workspace_id', $ws)->pluck('id')->all();
        $runs = DB::table('composition_runs')->where('workspace_id', $ws)->orWhereIn('conversation_id', $conversations)->pluck('id')->all();
        $byRun = ['composition_attempts', 'composition_trace_events', 'create_worker_assignments', 'create_plan_media'];
        $byConversation = ['create_plan_media', 'composition_revisions', 'create_attachments', 'create_messages', 'create_plans',
            'create_planning_jobs', 'create_documents', 'create_check_disputes', 'create_missed_defects'];
        foreach (array_chunk($runs, 500) as $ids) foreach ($byRun as $t) $this->delete($t, 'run_id', $ids);
        foreach (array_chunk($conversations, 500) as $ids) foreach ($byConversation as $t) $this->delete($t, 'conversation_id', $ids);
        foreach (array_chunk($runs, 500) as $ids) $this->delete('composition_runs', 'id', $ids);
        foreach (array_chunk($conversations, 500) as $ids) $this->delete('create_conversations', 'id', $ids);
        foreach (['api_keys', 'api_operations', 'api_quotes', 'create_pronunciations', 'create_style_notes', 'create_styles', 'ugc_take_reviews',
            'create_check_disputes', 'create_missed_defects', 'create_documents', 'create_planning_jobs'] as $t) $this->delete($t, 'workspace_id', [$ws]);
    }

    /** Conversations this person started in other workspaces (a client seat) stay there, credited to that workspace's owner. */
    public function handOver(User $user, ?int $keepWorkspaceId): void
    {
        $rows = DB::table('create_conversations')->where('created_by_user_id', $user->getKey())
            ->when($keepWorkspaceId, fn ($q) => $q->where('workspace_id', '!=', $keepWorkspaceId))->get(['id', 'workspace_id']);
        foreach ($rows as $row) {
            $owner = DB::table('workspaces')->where('id', $row->workspace_id)->value('owner_user_id');
            if ($owner && (int) $owner !== (int) $user->getKey()) DB::table('create_conversations')->where('id', $row->id)->update(['created_by_user_id' => $owner]);
        }
    }

    private function delete(string $table, string $column, array $ids): void
    {
        if ($ids && Schema::hasTable($table) && Schema::hasColumn($table, $column)) DB::table($table)->whereIn($column, $ids)->delete();
    }
}
