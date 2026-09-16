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
                // The full list, so the admin can drop individuals before
                // sending. A segment used to be all-or-nothing: no way to mail
                // "every free user except these two" without retyping the rest
                // by hand as a custom list.
                'recipients' => $users->map(fn ($u) => [
                    'email' => $u->email,
                    'name'  => $u->name,
                ])->values()->all(),
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
            // Addresses the admin unticked in the recipient list. Applied
            // after the segment resolves, so the segment's own safety rules —
            // skipping internal accounts and suspended workspaces — still run.
            'exclude'   => ['sometimes', 'array', 'max:500'],
            'exclude.*' => ['email'],
        ]);

        $users = $this->resolveSegment($validated['segment'], $validated['emails'] ?? []);

        $excluded = array_map('mb_strtolower', $validated['exclude'] ?? []);
        if ($excluded !== []) {
            $users = $users->reject(fn ($u) => in_array(mb_strtolower((string) $u->email), $excluded, true))->values();
        }

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
                'excluded'   => $excluded,
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
    /**
     * Turn a sentence about what you want into a subject and a body.
     *
     * Two shapes. Given one address it writes from that person's actual usage,
     * which is what makes the result worth sending. Given a segment it writes
     * from a description of the group — never from one member's details, since
     * a broadcast that names a project only one recipient has is worse than a
     * generic one.
     */
    public function draft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['sometimes', 'nullable', 'email'],
            'segment' => ['sometimes', 'nullable', Rule::in(self::SEGMENTS)],
            'instruction' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $instruction = trim((string) ($validated['instruction'] ?? ''));
        $email = $validated['email'] ?? null;
        $segment = $validated['segment'] ?? null;
        $user = null;

        if ($email) {
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                return $this->error('not_found', 'No user with that email.', 404);
            }
            $dossier = $this->buildDossier($user);
        } elseif ($segment && $segment !== 'custom') {
            $dossier = $this->buildSegmentDossier($segment);
        } else {
            return $this->error('no_audience', 'Pick a recipient or a segment first.', 422);
        }

        // Without an instruction this stays the feedback-ask it has always
        // been, so the existing button keeps working unchanged.
        $template = $instruction !== '' ? 'admin_composed_email' : 'admin_feedback_email';
        $inputs = ['dossier' => $dossier, 'sender_name' => 'Amara'];
        if ($instruction !== '') {
            $inputs['instruction'] = $instruction;
        }

        try {
            $result = app(\App\Services\Generation\AI\AIGenerationAdapter::class)->generate(
                $template,
                $inputs,
                900,
                0.5,
                ['usage_context' => ['workspace_id' => $user?->workspace_id, 'operation' => $template]],
            );
            $parsed = json_decode((string) $result['content'], true);
        } catch (\Throwable $e) {
            return $this->error('draft_failed', 'Could not draft: '.$e->getMessage(), 502);
        }

        if (! is_array($parsed) || empty($parsed['subject']) || empty($parsed['body'])) {
            return $this->error('draft_failed', 'Model returned an unusable draft — try again.', 502);
        }

        // The renderer only substitutes into the user prompt, so the sign-off
        // placeholder from the system prompt can survive verbatim.
        $parsed['body'] = str_replace(['{{sender_name}}', '{{ sender_name }}'], 'Amara', (string) $parsed['body']);

        return response()->json(['data' => [
            'subject' => (string) $parsed['subject'],
            'body'    => (string) $parsed['body'],
            'dossier' => $dossier,
        ]]);
    }

    /**
     * What is true of a group, for a broadcast.
     *
     * Deliberately aggregate. Drafting a segment email from one member's
     * dossier produces a letter that reads as personal to exactly one person
     * and as a mistake to everyone else.
     */
    private function buildSegmentDossier(string $segment): string
    {
        $users = $this->resolveSegment($segment, []);
        $ids = $users->pluck('workspace_id')->filter()->unique();

        $exports = \App\Models\ExportJob::query()->whereIn('workspace_id', $ids)
            ->where('status', 'completed')->count();
        $projects = \App\Models\Project::query()->whereIn('workspace_id', $ids)->count();
        $neverBuilt = $ids->count() - \App\Models\Project::query()->whereIn('workspace_id', $ids)
            ->distinct()->count('workspace_id');
        $newest = $users->max('created_at');
        $oldest = $users->min('created_at');

        $tiers = \App\Models\Workspace::query()->whereIn('id', $ids)
            ->selectRaw('coalesce(plan_tier, \'free\') as tier, count(*) as n')
            ->groupBy('tier')->pluck('n', 'tier');

        return implode("\n", array_filter([
            'Audience: the "'.$segment.'" segment — '.$users->count().' recipients.',
            'Plans: '.($tiers->map(fn ($n, $t) => "{$t}: {$n}")->implode(', ') ?: 'unknown'),
            "Between them: {$projects} projects and {$exports} completed exports.",
            $neverBuilt > 0 ? "{$neverBuilt} of them have never created a project." : null,
            $oldest ? 'Joined between '.\Carbon\Carbon::parse($oldest)->format('M j').' and '
                .\Carbon\Carbon::parse($newest)->format('M j').'.' : null,
            'This email goes to all of them, so write nothing that is only true of one person.',
        ]));
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
            // The onboarding flow auto-creates a sample titled "Wyvstudio Ad".
            // Without this note the model congratulates people on a project
            // the machine made — instantly reads as a bot that didn't look.
            $sample = ($p->title === 'Wyvstudio Ad') ? ' — AUTO-CREATED onboarding sample, not their own work; do not praise it' : '';
            $lines[] = sprintf('- "%s" (%s, from %s, %d scenes, %s)%s',
                $p->title ?: 'untitled', $p->status, $p->source_type ?: '?', $scenes, $p->created_at?->format('M j'), $sample);
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

    /**
     * What actually happened to the mail we sent.
     *
     * Separate from history(), which lists admin broadcasts. This is the
     * per-recipient record: every send, whether it was delivered, and whether
     * it was opened — including the automated mail nobody composed.
     */
    public function log(Request $request): JsonResponse
    {
        $v = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'nullable', 'string', 'max:190'],   // kept: older callers
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'mailable' => ['sometimes', 'nullable', 'string', 'max:120'],
            'opened' => ['sometimes', 'nullable', 'in:yes,no'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:200'],
        ]);

        $query = \App\Models\MailLogEntry::query()->orderByDesc('sent_at');

        // One box, three things worth searching: who it went to, what it said,
        // and the name of whatever sent it.
        $search = trim((string) ($v['search'] ?? $v['email'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(email) like ?', [$like])
                    ->orWhereRaw('LOWER(coalesce(subject, \'\')) like ?', [$like])
                    ->orWhereRaw('LOWER(coalesce(mailable, \'\')) like ?', [$like]);
            });
        }
        if (! empty($v['status'])) {
            $query->where('status', $v['status']);
        }
        if (! empty($v['mailable'])) {
            $query->where('mailable', $v['mailable']);
        }
        if (! empty($v['opened'])) {
            $v['opened'] === 'yes'
                ? $query->whereNotNull('first_opened_at')
                : $query->whereNull('first_opened_at');
        }

        // Counts and the type list come from the filtered set minus paging, so
        // the totals above the table describe what the table is showing.
        $counts = (clone $query)->reorder()->selectRaw('status, count(*) as n')
            ->groupBy('status')->pluck('n', 'status');
        $opened = (clone $query)->reorder()->whereNotNull('first_opened_at')->count();

        $page = $query->paginate($v['per_page'] ?? 50, ['*'], 'page', $v['page'] ?? 1);

        $rows = collect($page->items())->map(fn ($m) => [
            'id' => $m->id,
            'email' => $m->email,
            'mailable' => $m->mailable,
            'subject' => $m->subject,
            'status' => $m->status,
            'sent_at' => $m->sent_at?->toIso8601String(),
            'delivered_at' => $m->delivered_at?->toIso8601String(),
            'first_opened_at' => $m->first_opened_at?->toIso8601String(),
            'open_count' => (int) $m->open_count,
            'failure_reason' => $m->failure_reason,
        ]);

        return response()->json(['data' => [
            'entries' => $rows,
            'counts' => $counts,
            'opened_total' => $opened,
            // Every mailable we have actually sent, so the filter offers real
            // options rather than a hardcoded list that drifts.
            'mailables' => \App\Models\MailLogEntry::query()->whereNotNull('mailable')
                ->distinct()->orderBy('mailable')->pluck('mailable'),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            // Opens only arrive when open tracking is on for the sending
            // domain in Resend; zero here with delivered mail present usually
            // means the setting, not the readers.
            'open_tracking_hint' => $opened === 0 && ($counts['delivered'] ?? 0) > 0,
        ]]);
    }

    /** Previously sent mail, straight from the audit log — one source of truth. */
    public function history(Request $request): JsonResponse
    {
        $v = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);

        $page = AdminAuditLog::query()
            ->where('action', 'admin_mail_sent')
            ->with('admin:id,email')
            ->orderByDesc('id')
            ->paginate($v['per_page'] ?? 20, ['*'], 'page', $v['page'] ?? 1);

        $rows = collect($page->items())
            ->map(function (AdminAuditLog $log) {
                $subject = $log->payload_json['subject'] ?? null;
                $recipients = $log->payload_json['recipients'] ?? [];

                // How the broadcast actually landed. Matched on subject and
                // recipients rather than an id, because the audit row predates
                // the mail log and the two were never linked; a re-used subject
                // is narrowed by the send window either way.
                $stats = null;
                if ($subject && $log->created_at) {
                    $rows = \App\Models\MailLogEntry::query()
                        ->where('subject', mb_substr($subject, 0, 255))
                        ->whereBetween('sent_at', [
                            $log->created_at->copy()->subMinutes(5),
                            $log->created_at->copy()->addHours(6),
                        ])->get(['status', 'first_opened_at']);

                    if ($rows->isNotEmpty()) {
                        $stats = [
                            'sent' => $rows->count(),
                            'delivered' => $rows->whereIn('status', ['delivered', 'opened'])->count(),
                            'opened' => $rows->whereNotNull('first_opened_at')->count(),
                            'bounced' => $rows->whereIn('status', ['bounced', 'failed'])->count(),
                        ];
                    }
                }

                return [
                    'id'         => $log->id,
                    'sent_at'    => $log->created_at?->toIso8601String(),
                    'sent_by'    => $log->admin?->email,
                    'segment'    => $log->payload_json['segment'] ?? null,
                    'subject'    => $subject,
                    'body'       => $log->payload_json['body'] ?? null,   // sends before body-logging show null
                    'recipients' => $recipients,
                    // Null for anything sent before the mail log existed —
                    // shown as "—" rather than as zero opens, which would read
                    // as nobody having read it.
                    'stats'      => $stats,
                ];
            });

        return response()->json(['data' => [
            'sends' => $rows,
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]]);
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
            ->whereNotIn('users.email', (array) config('admin.internal_emails', []))
            // Nor to somebody else's client. They were invited into an agency's
            // workspace; a broadcast from us about plans and features would be
            // a product pitch to a person who never bought anything, and it
            // would tell them their agency's supplier's name.
            ->whereNotIn('users.role', array_keys(\App\Models\User::CLIENT_SEATS))
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
        $safe = $this->linkify($safe);

        // Paragraphs are split before nl2br, not after. nl2br inserts its tag
        // *between* the two newlines, so the blank-line split never matched
        // and every message came out as one paragraph of <br> — which the
        // composer's own hint promises it is not.
        $paragraphs = preg_split("/\n\s*\n/", $safe) ?: [$safe];

        return implode('', array_map(
            fn (string $p) => '<p>'.nl2br(trim($p)).'</p>',
            array_filter($paragraphs, fn (string $p) => trim($p) !== ''),
        )) ?: '<p></p>';
    }

    /**
     * Turn bare URLs into links.
     *
     * Runs on text that has already been escaped, so the only markup in the
     * output is the anchors added here — a URL containing a quote or an angle
     * bracket arrives as an entity and cannot break out of the attribute.
     *
     * Deliberately narrow: http and https only. Accepting arbitrary schemes
     * would let a composed email carry javascript: or data:, and a link in a
     * message that came from us is exactly the one a recipient trusts.
     */
    private function linkify(string $escaped): string
    {
        return (string) preg_replace_callback(
            // Stops at whitespace or an entity, so a URL at the end of a
            // sentence does not swallow the full stop that follows it.
            '~\bhttps?://[^\s<>"\']+~i',
            function (array $m): string {
                $url = $m[0];

                // Trailing punctuation belongs to the sentence, not the link:
                // "see https://x.test/page." should not link the full stop.
                // Brackets only count as trailing when unbalanced, so
                // Wikipedia-style URLs survive.
                $trail = '';
                while ($url !== '' && str_contains('.,;:!?', substr($url, -1))) {
                    $trail = substr($url, -1).$trail;
                    $url = substr($url, 0, -1);
                }
                while (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
                    $trail = ')'.$trail;
                    $url = substr($url, 0, -1);
                }

                if ($url === '') {
                    return $trail;
                }

                // Already escaped, so this is safe in both the attribute and
                // the text. Shown in full: a recipient deciding whether to
                // trust a link needs to see where it goes.
                return '<a href="'.$url.'">'.$url.'</a>'.$trail;
            },
            $escaped,
        );
    }
}
