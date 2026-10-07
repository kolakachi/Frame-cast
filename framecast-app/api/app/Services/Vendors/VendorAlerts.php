<?php
namespace App\Services\Vendors;

use App\Mail\VendorAlertMail;
use Illuminate\Support\Facades\{Cache, DB, Http, Log, Mail};

/**
 * What happens when a vendor fails (docs/product/archive/create/create-vendor-errors-todo.md). Every classified failure is kept in
 * vendor_incidents for the daily summary. A refusal is also a moderation event. Our own account running dry or
 * misconfigured (vendor_credit, vendor_config) alerts the super admins and ADMIN_ALERT_EMAILS at once (at most once
 * an hour per vendor and kind, and once more when it recovers), and holds new work that needs that vendor for a few
 * minutes so failures do not pile up. Vendors expose no balance to watch, so the first failure is the signal.
 */
final class VendorAlerts
{
    public const OURS = ['vendor_credit', 'vendor_config'];
    /** Where each vendor's account is fixed. */
    public const FIX = ['anthropic' => 'https://console.anthropic.com/settings/billing', 'replicate' => 'https://replicate.com/account/billing',
        'openai' => 'https://platform.openai.com/settings/organization/billing', 'google' => 'https://console.cloud.google.com/billing'];
    /** What the user is told a vendor is (never the vendor's name). */
    private const SERVICE = ['anthropic' => 'Our AI model', 'replicate' => 'The image, voice and video models', 'openai' => 'The transcription service', 'google' => 'The voice service'];

    /** Classify a failure, keep it, and act on it. Returns the kind. */
    public static function observe(string $vendor, string $text, ?int $status = null, array $context = []): string
    {
        $kind = VendorError::classify($text, $status);
        if ($kind !== 'other') self::record($vendor, $kind, $text, $context);
        return $kind;
    }

    public static function record(string $vendor, string $kind, string $message, array $context = []): void
    {
        $message = mb_substr(trim($message), 0, 600);
        rescue(fn () => DB::table('vendor_incidents')->insert(['vendor' => $vendor, 'kind' => $kind, 'message' => $message,
            'run_id' => $context['run_id'] ?? null, 'workspace_id' => $context['workspace_id'] ?? null, 'context_json' => json_encode(array_diff_key($context, ['prompt' => 1])), 'created_at' => now()]), report: false);
        if ($kind === 'content_refused') rescue(fn () => app(\App\Services\Moderation\ModerationService::class)->recordRejection($message, [
            'workspace_id' => $context['workspace_id'] ?? null, 'user_id' => $context['user_id'] ?? null, 'operation' => 'create:'.$vendor.(isset($context['kind']) ? ':'.$context['kind'] : ''),
            'prompt' => $context['prompt'] ?? null, 'metadata' => ['vendor' => $vendor, 'run_id' => $context['run_id'] ?? null]]), report: false);
        if (in_array($kind, self::OURS, true)) {
            Cache::put('vendor-down:'.$vendor, ['kind' => $kind, 'message' => $message, 'at' => now()->toIso8601String()], now()->addMinutes((int) config('create.vendor_hold_minutes', 10)));
            self::alert($vendor, $kind, $message, $context);
        }
    }

    /** The hold on a vendor after our account failed, or null. */
    public static function down(string $vendor): ?array { return Cache::get('vendor-down:'.$vendor); }

    /** Refuses new work while a vendor it needs is held. */
    public static function assertUp(array $vendors): void
    {
        foreach (array_unique($vendors) as $v) if ($d = self::down($v)) abort(503, self::userMessage($v, $d['kind']));
    }

    /** A vendor call that worked: after an alert, the hold ends and the team hears it recovered. */
    public static function recovered(string $vendor): void
    {
        if (! Cache::pull('vendor-alerted:'.$vendor)) return;
        Cache::forget('vendor-down:'.$vendor);
        self::send('recovered', $vendor, 'recovered', 'A call to '.$vendor.' worked again.', []);
    }

    public static function userMessage(string $vendor, string $kind): string
    {
        $service = self::SERVICE[$vendor] ?? 'A service we use';
        return match ($kind) {
            'busy' => $service.' is busy right now. Nothing was charged for it; press Retry in a minute.',
            'content_refused' => $service.' declined part of this request under its content rules. Nothing was charged for it; change the wording or the image and try again.',
            'vendor_credit', 'vendor_config' => $service.' is temporarily unavailable on our side. The team has been notified and nothing was charged for it; press Retry in a few minutes.',
            default => $service.' did not complete this request. Nothing was charged for it.',
        };
    }

    /** Super admins plus ADMIN_ALERT_EMAILS. */
    public static function recipients(): array
    {
        $admins = rescue(fn () => DB::table('users')->whereIn('role', ['super_admin'])->whereNotNull('email')->pluck('email')->all(), [], false);
        return array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), [...$admins, ...(array) config('create.admin_alert_emails', [])]))));
    }

    private static function alert(string $vendor, string $kind, string $message, array $context): void
    {
        Cache::put('vendor-alerted:'.$vendor, true, now()->addDays(2));
        if (! Cache::add('vendor-alert:'.$vendor.':'.$kind, true, now()->addHour())) return;
        $runs = rescue(fn () => DB::table('vendor_incidents')->where('vendor', $vendor)->where('kind', $kind)->where('created_at', '>=', now()->subHour())->whereNotNull('run_id')->distinct()->pluck('run_id')->all(), [], false);
        self::send('alert', $vendor, $kind, $message, ['runs' => $runs, 'fix' => self::FIX[$vendor] ?? null]);
    }

    private static function send(string $type, string $vendor, string $kind, string $message, array $extra): void
    {
        $subject = $type === 'recovered' ? 'Recovered: '.$vendor.' is working again' : 'Action needed: '.$vendor.' '.($kind === 'vendor_credit' ? 'is out of credit' : 'rejected our key or account');
        foreach (self::recipients() as $to) rescue(fn () => Mail::to($to)->queue(new VendorAlertMail($subject, $vendor, $kind, $message, $extra)), report: false);
        // The Slack channel too, when it is set up.
        $token = (string) config('services.slack.notifications.bot_user_oauth_token'); $channel = (string) config('services.slack.notifications.channel');
        if ($token !== '' && $channel !== '') rescue(fn () => Http::withToken($token)->timeout(10)->post('https://slack.com/api/chat.postMessage',
            ['channel' => $channel, 'text' => $subject."\n".$message.(! empty($extra['fix']) ? "\nFix: ".$extra['fix'] : '')]), report: false);
        Log::warning('Vendor '.$type, ['vendor' => $vendor, 'kind' => $kind, 'message' => mb_substr($message, 0, 300)]);
    }
}
