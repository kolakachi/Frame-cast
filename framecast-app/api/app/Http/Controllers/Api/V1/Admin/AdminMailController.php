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
