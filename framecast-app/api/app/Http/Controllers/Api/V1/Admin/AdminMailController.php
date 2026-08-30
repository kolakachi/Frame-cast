<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminDirectMail;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Send email from the admin panel — one customer, or a segment broadcast.
 *
 * Sends from the workspace's configured from-address (hello@wyvstudio.com).
 * Broadcasts always exclude @wyvstudio.com accounts and suspended workspaces
 * (a refunded customer must never receive marketing), and every send is
 * written to the admin audit log with its full recipient list.
 */
class AdminMailController extends Controller
{
    private const SEGMENTS = ['custom', 'all', 'paying', 'appsumo', 'free'];

    public function recipients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'segment' => ['required', Rule::in(self::SEGMENTS)],
        ]);

        $users = $this->resolveSegment($validated['segment'], []);

        return response()->json([
            'data' => [
                'count'  => $users->count(),
                'sample' => $users->take(12)->pluck('email')->all(),
            ],
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'segment'  => ['required', Rule::in(self::SEGMENTS)],
            'emails'   => ['required_if:segment,custom', 'array', 'max:100'],
            'emails.*' => ['email'],
            'subject'  => ['required', 'string', 'max:200'],
            'body'     => ['required', 'string', 'max:10000'],
        ]);

        $users = $this->resolveSegment($validated['segment'], $validated['emails'] ?? []);

        if ($users->isEmpty()) {
            return $this->error('no_recipients', 'No matching recipients.', 422);
        }

        foreach ($users as $user) {
            Mail::to($user->email)->queue(new AdminDirectMail(
                $validated['subject'],
                $this->renderBody($validated['body'], $user),
            ));
        }

        AdminAuditLog::record(
            adminUserId: $admin->getKey(),
            action: 'admin_mail_sent',
            targetType: 'segment',
            targetId: null,
            payload: [
                'segment'    => $validated['segment'],
                'subject'    => $validated['subject'],
                'body'       => mb_substr($validated['body'], 0, 10000),
                'recipients' => $users->pluck('email')->all(),
            ],
            ip: $request->ip(),
        );

        return response()->json([
            'data' => [
                'queued'     => $users->count(),
                'recipients' => $users->pluck('email')->all(),
            ],
        ]);
    }

    /**
     * Usage dossier + AI-drafted feedback email for one customer.
     *
     * The "agent" is deliberately grounded: it assembles hard facts from our
     * own database (projects, sources, failures, exports, credit spend,
     * recency) and the model may reference ONLY those — the prompt forbids
     * invented activity. The admin reviews and edits before sending; nothing
     * is sent from here.
     */
    public function draft(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->where('email', $validated['email'])->first();
        if (! $user) {
            return $this->error('not_found', 'No user with that email.', 404);
        }

        $dossier = $this->buildDossier($user);

        try {
            $result = app(\App\Services\Generation\AI\AIGenerationAdapter::class)->generate(
                'admin_feedback_email',
                ['dossier' => $dossier, 'sender_name' => 'Amara'],
                600,
                0.5,
                ['usage_context' => ['workspace_id' => $user->workspace_id, 'operation' => 'admin_feedback_email']],
            );
            $parsed = json_decode((string) $result['content'], true);
        } catch (\Throwable $e) {
            return $this->error('draft_failed', 'Could not draft: '.$e->getMessage(), 502);
        }

        if (! is_array($parsed) || empty($parsed['subject']) || empty($parsed['body'])) {
            return $this->error('draft_failed', 'Model returned an unusable draft — try again.', 502);
        }

        return response()->json(['data' => [
            'subject' => (string) $parsed['subject'],
            'body'    => (string) $parsed['body'],
            'dossier' => $dossier,
        ]]);
    }

    /** Hard facts about one user's product usage, as plain text for the model AND the admin. */
    private function buildDossier(User $user): string
    {
        $ws = $user->workspace;
        $lines = [];
        $lines[] = 'Name: '.($user->name ?: '(none)').' | Email: '.$user->email;
        $lines[] = 'Plan: '.($ws->plan_tier ?? 'free').' | Signed up: '.$user->created_at?->format('M j').' ('.$user->created_at?->diffForHumans().')';
        $lines[] = 'Last seen: '.($user->last_seen_at?->diffForHumans() ?? 'unknown');

        $projects = \App\Models\Project::query()
            ->where('workspace_id', $user->workspace_id)
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'title', 'status', 'source_type', 'visual_generation_mode', 'created_at']);

        $exports = \App\Models\ExportJob::query()
            ->where('workspace_id', $user->workspace_id)->where('status', 'completed')->count();

        $spent = (int) \App\Models\CreditLedgerEntry::query()
            ->where('workspace_id', $user->workspace_id)->where('credits', '>', 0)->sum('credits');

        $lines[] = "Projects: {$projects->count()} | Completed exports: {$exports} | Credits spent: {$spent}";

        foreach ($projects as $p) {
            $scenes = $p->scenes()->count();
            $lines[] = sprintf('- "%s" (%s, from %s, %d scenes, %s)',
                $p->title ?: 'untitled', $p->status, $p->source_type ?: '?', $scenes, $p->created_at?->format('M j'));
        }

        // Failures are often the most useful specifics of all.
        $failed = $projects->firstWhere('status', 'failed');
        if ($failed) {
            $msg = data_get(\App\Events\GenerationProgressed::getProgress($failed->id), 'last_message');
            if ($msg) {
                $lines[] = 'A generation failed for them with: "'.mb_substr($msg, 0, 160).'"';
            }
        }

        return implode("\n", $lines);
    }

    /** Previously sent mail, straight from the audit log — one source of truth. */
    public function history(Request $request): JsonResponse
    {
        $rows = AdminAuditLog::query()
            ->where('action', 'admin_mail_sent')
            ->with('admin:id,email')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AdminAuditLog $log) => [
                'id'         => $log->id,
                'sent_at'    => $log->created_at?->toIso8601String(),
                'sent_by'    => $log->admin?->email,
                'segment'    => $log->payload_json['segment'] ?? null,
                'subject'    => $log->payload_json['subject'] ?? null,
                'body'       => $log->payload_json['body'] ?? null,   // sends before body-logging show null
                'recipients' => $log->payload_json['recipients'] ?? [],
            ]);

        return response()->json(['data' => ['sends' => $rows]]);
    }

    /** @return \Illuminate\Support\Collection<int,User> */
    private function resolveSegment(string $segment, array $emails)
    {
        if ($segment === 'custom') {
            // Explicit addresses: send to exactly who the admin typed. Users
            // not in the DB still get the mail (no {name} personalisation);
            // an admin emailing a prospect is legitimate.
            $known = User::query()->whereIn('email', $emails)->get()->keyBy('email');

            return collect($emails)->unique()->map(
                fn (string $e) => $known[$e] ?? new User(['email' => $e]),
            )->values();
        }

        $q = User::query()
            ->join('workspaces', 'workspaces.id', '=', 'users.workspace_id')
            ->whereNotNull('users.email')
            // Never broadcast to internal accounts or suspended (refunded /
            // banned) workspaces.
            ->where('users.email', 'not like', '%@wyvstudio.com')
            ->where(fn ($w) => $w->whereNull('workspaces.status')->orWhere('workspaces.status', '!=', 'suspended'));

        match ($segment) {
            'paying'  => $q->whereNotNull('workspaces.plan_tier')->where('workspaces.plan_tier', '!=', 'free'),
            'appsumo' => $q->where('workspaces.plan_tier', 'like', 'appsumo%'),
            'free'    => $q->where(fn ($w) => $w->whereNull('workspaces.plan_tier')->orWhere('workspaces.plan_tier', 'free')),
            default   => null, // 'all'
        };

        return $q->get(['users.*'])->unique('email')->values();
    }

    /**
     * Plain text -> safe HTML. Escape FIRST, then substitute {name} and
     * convert breaks — the admin's text and the user's name both pass
     * through the escaper before any HTML exists.
     */
    private function renderBody(string $body, User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        $first = $name !== '' ? preg_split('/\s+/', $name)[0] : 'there';

        $safe = e($body);
        $safe = str_replace(['{name}', '&#123;name&#125;'], e($first), $safe);

        return '<p>'.str_replace("\n\n", '</p><p>', nl2br($safe)).'</p>';
    }
}
