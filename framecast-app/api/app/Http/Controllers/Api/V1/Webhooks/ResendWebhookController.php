<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\MailLogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What happened to an email after we handed it over.
 *
 * Sending goes over Resend's SMTP rather than their API, so we never learn
 * their message id at send time and cannot key on it. Events are matched to
 * the most recent unmatched send to that address instead, and the id is
 * recorded on first match so later events for the same email land directly.
 *
 * That is looser than an id, and it is the right trade: the alternative is
 * switching transports on a system that is delivering fine, to gain precision
 * on an audit trail.
 */
class ResendWebhookController extends Controller
{
    /** Resend event → the column it stamps and the status it implies. */
    private const EVENTS = [
        'email.sent' => ['sent_at', 'sent'],
        'email.delivered' => ['delivered_at', 'delivered'],
        'email.delivery_delayed' => [null, null],
        'email.opened' => ['first_opened_at', 'opened'],
        'email.clicked' => ['first_opened_at', 'opened'],
        'email.bounced' => ['bounced_at', 'bounced'],
        'email.complained' => ['complained_at', 'complained'],
        'email.failed' => [null, 'failed'],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->signatureValid($request)) {
            // 401 rather than 403: Resend retries on 5xx, and a wrong secret
            // should be visible in their dashboard rather than retried forever.
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $type = (string) $request->input('type', '');
        if (! array_key_exists($type, self::EVENTS)) {
            return response()->json(['data' => ['ignored' => $type]]);
        }

        $data = (array) $request->input('data', []);
        $recipients = array_filter((array) ($data['to'] ?? []));
        $providerId = (string) ($data['email_id'] ?? '');
        $subject = (string) ($data['subject'] ?? '');

        [$column, $status] = self::EVENTS[$type];

        $matched = 0;
        foreach ($recipients as $recipient) {
            $entry = $this->locate((string) $recipient, $providerId, $subject);
            if (! $entry) {
                continue;
            }
            $this->apply($entry, $type, $column, $status, $providerId, $data);
            $matched++;
        }

        if ($matched === 0) {
            // Worth seeing: a webhook with nothing to attach to usually means
            // mail is going out from somewhere this log does not cover.
            Log::info('ResendWebhook: no matching send', [
                'type' => $type, 'recipients' => count($recipients),
            ]);
        }

        return response()->json(['data' => ['matched' => $matched]]);
    }

    private function locate(string $recipient, string $providerId, string $subject): ?MailLogEntry
    {
        if ($providerId !== '') {
            $byId = MailLogEntry::query()->where('provider_message_id', $providerId)->first();
            if ($byId) {
                return $byId;
            }
        }

        $email = strtolower($recipient);

        // Newest first, and only rows that have not already been claimed by a
        // different Resend id — otherwise a second send to the same address
        // keeps absorbing the first one's events.
        return MailLogEntry::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where(function ($q) use ($providerId) {
                $q->whereNull('provider_message_id');
                if ($providerId !== '') {
                    $q->orWhere('provider_message_id', $providerId);
                }
            })
            ->when($subject !== '', fn ($q) => $q->where('subject', mb_substr($subject, 0, 255)))
            ->where('sent_at', '>=', now()->subDays(30))
            ->orderByDesc('sent_at')
            ->first();
    }

    private function apply(MailLogEntry $entry, string $type, ?string $column, ?string $status, string $providerId, array $data): void
    {
        if ($providerId !== '' && ! $entry->provider_message_id) {
            $entry->provider_message_id = $providerId;
        }

        if ($type === 'email.opened' || $type === 'email.clicked') {
            // Opens repeat — one per image load, per client, forever. Count
            // them, but keep the first as the moment it was read.
            $entry->first_opened_at ??= now();
            $entry->last_opened_at = now();
            $entry->open_count = (int) $entry->open_count + 1;
        } elseif ($column) {
            $entry->{$column} ??= now();
        }

        if ($type === 'email.bounced' || $type === 'email.failed' || $type === 'email.complained') {
            $entry->failure_reason = mb_substr(
                (string) ($data['reason'] ?? $data['bounce']['message'] ?? $data['error'] ?? $type),
                0, 500,
            );
        }

        if ($status) {
            $entry->advanceTo($status);
        }

        $entry->save();
    }

    /**
     * Resend signs with Svix: the signed payload is "{id}.{timestamp}.{body}",
     * and the header can carry several space-separated versioned signatures.
     */
    private function signatureValid(Request $request): bool
    {
        $secret = (string) config('services.resend.webhook_secret', '');
        if ($secret === '') {
            // Unconfigured means unverifiable. Refusing is the safe default:
            // anything else accepts forged delivery state from anyone.
            Log::warning('ResendWebhook: no signing secret configured, refusing');

            return false;
        }

        $id = (string) $request->header('svix-id', '');
        $timestamp = (string) $request->header('svix-timestamp', '');
        $signatures = (string) $request->header('svix-signature', '');
        if ($id === '' || $timestamp === '' || $signatures === '') {
            return false;
        }

        // Replay window. Svix's own tolerance is five minutes.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $key = base64_decode((string) preg_replace('/^whsec_/', '', $secret), true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac(
            'sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true,
        ));

        foreach (explode(' ', $signatures) as $candidate) {
            // Each is "v1,<base64>"; compared in constant time.
            $parts = explode(',', $candidate, 2);
            if (count($parts) === 2 && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }
}
