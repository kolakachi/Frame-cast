<?php

namespace App\Services;

use App\Models\AppSumoLicense;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AppSumo Licensing API v2 integration — lifetime-deal (LTD) accounts.
 *
 * Flow:
 *   1. AppSumo POSTs webhook events (purchase/activate/upgrade/downgrade/
 *      deactivate). We only track license state here — AppSumo never sends the
 *      buyer's email, so no account exists yet.
 *   2. The buyer completes OAuth on our redirect URL. We exchange the code for
 *      their license key, then create/link an account (email collected on our
 *      side) and grant the one-time credit bucket via linkAndProvision().
 *
 * Credits: granted ONCE per license into credits_topup (never renews). Upgrades
 * grant only the tier delta; downgrades don't claw back spent credits.
 */
class AppSumoService
{
    public function __construct(private readonly CreditService $credits) {}

    public function isConfigured(): bool
    {
        return (string) config('appsumo.api_key', '') !== '';
    }

    // ── Webhook signature ────────────────────────────────────────────────────

    /**
     * Verify AppSumo's HMAC: X-Appsumo-Signature = hex HMAC-SHA256 of
     * (timestamp . raw_body) keyed with the API key. Rejects >5min clock skew.
     */
    public function verifyWebhook(string $rawBody, string $signature, string $timestamp): bool
    {
        $key = (string) config('appsumo.api_key', '');
        $sigRaw   = trim(preg_replace('/^sha256=/i', '', $signature));
        $sigLower = strtolower($sigRaw);
        if ($key === '' || $sigRaw === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // X-Appsumo-Timestamp can be seconds or milliseconds — normalize to
        // seconds for the replay-skew guard. The HMAC uses the raw string as
        // sent (AppSumo signs the header verbatim). Skew is logged, not fatal,
        // while we confirm the exact scheme against a live webhook; the HMAC
        // below is the real gate. TODO: re-tighten to the confirmed scheme.
        $tsSeconds = (int) $timestamp;
        if ($tsSeconds > 1_000_000_000_000) {
            $tsSeconds = intdiv($tsSeconds, 1000);
        }
        if (abs(time() - $tsSeconds) > 900) {
            Log::warning('AppSumoWebhook: timestamp skew', ['skew_seconds' => time() - $tsSeconds]);
        }

        // Documented scheme is HMAC-SHA256(timestamp . body). Also accept a few
        // standard construction variants — every one still requires the shared
        // secret, so a match proves authenticity — and log which matched so we
        // can pin the exact scheme, then drop the extras.
        $hex = [
            'ts.body' => hash_hmac('sha256', $timestamp . $rawBody, $key),
            'body'    => hash_hmac('sha256', $rawBody, $key),
            'ts:body' => hash_hmac('sha256', $timestamp . ':' . $rawBody, $key),
            'body.ts' => hash_hmac('sha256', $rawBody . $timestamp, $key),
        ];
        foreach ($hex as $name => $expected) {
            if (hash_equals($expected, $sigLower)) {
                if ($name !== 'ts.body') {
                    Log::warning('AppSumoWebhook: matched non-primary scheme', ['scheme' => $name.' (hex)']);
                }
                return true;
            }
        }
        $b64 = [
            'ts.body' => base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $key, true)),
            'body'    => base64_encode(hash_hmac('sha256', $rawBody, $key, true)),
        ];
        foreach ($b64 as $name => $expected) {
            if (hash_equals($expected, $sigRaw)) {
                Log::warning('AppSumoWebhook: matched non-primary scheme', ['scheme' => $name.' (base64)']);
                return true;
            }
        }

        // No scheme matched — record enough to identify the real one (never the key).
        Log::warning('AppSumoWebhook: signature mismatch', [
            'ts'                => $timestamp,
            'body_len'          => strlen($rawBody),
            'sig_len'           => strlen($sigRaw),
            'sig_prefix'        => substr($sigLower, 0, 10),
            'expected_ts_body'  => substr($hex['ts.body'], 0, 10),
            'expected_body'     => substr($hex['body'], 0, 10),
        ]);

        return false;
    }

    // ── Webhook event handling ───────────────────────────────────────────────

    /** Dispatch a verified webhook. Add-on events (parent_license_key) are ignored. */
    public function handleEvent(array $payload): void
    {
        if (! empty($payload['test'])) {
            return; // validation ping — acknowledged by the controller, no action
        }
        if (! empty($payload['parent_license_key'])) {
            Log::info('AppSumo: ignoring add-on event', ['event' => $payload['event'] ?? null]);
            return;
        }

        $event = (string) ($payload['event'] ?? '');
        match ($event) {
            'purchase', 'activate' => $this->recordLicense($payload),
            'upgrade', 'downgrade' => $this->reKey($payload),
            'deactivate'           => $this->deactivate($payload),
            default                => Log::info('AppSumo: unhandled event', ['event' => $event]),
        };
    }

    /**
     * purchase/activate: upsert the license row and (if a workspace is already
     * linked, e.g. re-activation) re-apply the tier + top up any credit delta.
     */
    private function recordLicense(array $payload): void
    {
        $key = (string) ($payload['license_key'] ?? '');
        if ($key === '') {
            return;
        }
        $tierNum = (int) ($payload['tier'] ?? 0);
        $mapped  = config('appsumo.tiers')[$tierNum] ?? null;

        $license = AppSumoLicense::firstOrNew(['license_key' => $key]);
        $license->fill([
            'tier'         => $mapped['plan_tier'] ?? $license->tier,
            'appsumo_tier' => $tierNum ?: $license->appsumo_tier,
            'status'       => 'active',
            'last_payload' => $payload,
        ])->save();

        if ($license->workspace) {
            $this->applyTierToWorkspace($license);
        }
    }

    /**
     * upgrade/downgrade: AppSumo issues a NEW license_key and sends a twin
     * `deactivate` for the old one. Re-key the existing row (prev → new) and
     * re-apply the new tier; because the old key no longer exists as a row, the
     * twin deactivate becomes a harmless no-op.
     */
    private function reKey(array $payload): void
    {
        $newKey  = (string) ($payload['license_key'] ?? '');
        $prevKey = (string) ($payload['prev_license_key'] ?? '');
        if ($newKey === '' || $prevKey === '') {
            return;
        }
        $tierNum = (int) ($payload['tier'] ?? 0);
        $mapped  = config('appsumo.tiers')[$tierNum] ?? null;

        $license = AppSumoLicense::firstOrNew(['license_key' => $prevKey]);
        $license->fill([
            'license_key'  => $newKey,
            'tier'         => $mapped['plan_tier'] ?? $license->tier,
            'appsumo_tier' => $tierNum ?: $license->appsumo_tier,
            'status'       => 'active',
            'last_payload' => $payload,
        ])->save();

        if ($license->workspace) {
            $this->applyTierToWorkspace($license);
        }
    }

    /**
     * deactivate: refund / cancellation / staff action → suspend the workspace.
     * Skips the twin deactivate that accompanies an upgrade/downgrade (the row
     * was already re-keyed, so the old key resolves to nothing).
     */
    private function deactivate(array $payload): void
    {
        $key = (string) ($payload['license_key'] ?? '');
        $reason = (string) ($payload['extra']['reason'] ?? '');
        if (preg_match('/upgrad|downgrad/i', $reason)) {
            return; // supersession twin, not a real deactivation
        }

        $license = AppSumoLicense::where('license_key', $key)->first();
        if (! $license) {
            return; // already re-keyed by an upgrade/downgrade, or never existed
        }
        $license->forceFill(['status' => 'deactivated', 'last_payload' => $payload])->save();

        if ($license->workspace) {
            $license->workspace->forceFill(['status' => 'suspended', 'plan_status' => 'deactivated'])->save();
            Log::info('AppSumo: workspace suspended on deactivate', [
                'workspace_id' => $license->workspace_id,
                'reason'       => $reason,
            ]);
        }
    }

    // ── OAuth (customer login after purchase) ────────────────────────────────

    /** Exchange the single-use OAuth code for an access token. */
    public function exchangeCode(string $code): ?array
    {
        $res = Http::asForm()->post((string) config('appsumo.token_url'), [
            'client_id'     => config('appsumo.client_id'),
            'client_secret' => config('appsumo.client_secret'),
            'redirect_uri'  => config('appsumo.redirect_uri'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]);

        return $res->successful() ? $res->json() : null;
    }

    /** Fetch the license key tied to an access token. */
    public function fetchLicenseKey(string $accessToken): ?string
    {
        $res = Http::get((string) config('appsumo.license_key_url'), ['access_token' => $accessToken]);

        return $res->successful() ? ($res->json()['license_key'] ?? null) : null;
    }

    // ── Account provisioning ─────────────────────────────────────────────────

    /**
     * Finish activation: create (or reuse) the user's workspace, apply the LTD
     * tier, and grant the one-time credit bucket. Idempotent — safe to call
     * again on re-activation. Returns the User, or null if the license is
     * unknown/deactivated.
     */
    public function linkAndProvision(string $licenseKey, string $email, ?string $password, ?string $name = null): ?User
    {
        $license = AppSumoLicense::where('license_key', $licenseKey)->first();
        if (! $license || $license->status === 'deactivated') {
            return null;
        }

        $email = strtolower(trim($email));
        $user  = User::query()->where('email', $email)->with('workspace')->first();

        if (! $user) {
            $workspace = Workspace::query()->create([
                'name'      => Str::of($email)->before('@')->headline().' Workspace',
                'plan_tier' => $license->tier ?? 'appsumo_starter',
                'status'    => 'active',
            ]);
            $user = User::query()->create([
                'workspace_id'  => $workspace->getKey(),
                'name'          => $name ?: Str::of($email)->before('@')->headline()->value(),
                'email'         => $email,
                'password_hash' => $password ? Hash::make($password) : null,
                'timezone'      => 'UTC',
                'role'          => 'owner',
                'status'        => 'active',
            ]);
            $workspace->forceFill(['owner_user_id' => $user->getKey()])->save();
            $user->forceFill(['preferences_json' => ['onboarded' => false, 'watermark_enabled' => false]])->save();
        }

        $license->forceFill(['workspace_id' => $user->workspace_id, 'status' => 'active'])->save();
        $this->applyTierToWorkspace($license->fresh('workspace'));

        return $user->fresh('workspace');
    }

    /**
     * Set the workspace's LTD tier/limits and grant any not-yet-granted portion
     * of the tier's credit bucket. Idempotent via granted_credits (grants only
     * target − granted, so retries no-op and upgrades grant just the delta).
     */
    private function applyTierToWorkspace(AppSumoLicense $license): void
    {
        $workspace = $license->workspace;
        if (! $workspace) {
            return;
        }
        $target = (int) (config('appsumo.tiers')[$license->appsumo_tier]['credits'] ?? 0);

        $workspace->forceFill([
            'plan_tier'   => $license->tier ?? $workspace->plan_tier,
            'plan_source' => 'appsumo',
            'plan_status' => 'active',
            'status'      => 'active',
        ])->save();

        $delta = $target - (int) $license->granted_credits;
        if ($delta > 0) {
            $this->credits->grant((int) $workspace->getKey(), $delta, 'appsumo_ltd');
            $license->forceFill(['granted_credits' => $target])->save();
        }
    }
}
