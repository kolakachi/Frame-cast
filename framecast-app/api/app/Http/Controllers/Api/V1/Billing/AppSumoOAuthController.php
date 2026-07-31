<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Services\AppSumoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * AppSumo OAuth (customer login after purchase).
 *
 *  callback  — AppSumo redirects the buyer here with ?code=. We exchange it for
 *              their license key, wrap it in a short-lived signed token, and
 *              send them to the SPA activation page to pick an email/password.
 *  activate  — the SPA posts {token,email,password}; we provision the LTD
 *              workspace + one-time credits. The SPA then logs in normally.
 */
class AppSumoOAuthController extends Controller
{
    private const TOKEN_TTL = 900; // 15 min to finish account creation

    public function __construct(private readonly AppSumoService $appsumo) {}

    /** GET /api/v1/appsumo/oauth/callback?code=... */
    public function callback(Request $request): RedirectResponse
    {
        $base = rtrim((string) config('appsumo.activate_redirect'), '/');
        $code = (string) $request->query('code', '');

        if ($code === '' || ! $this->appsumo->isConfigured()) {
            return redirect()->away($base.'?error=oauth');
        }

        $token = $this->appsumo->exchangeCode($code);
        $licenseKey = $token['access_token'] ?? null
            ? $this->appsumo->fetchLicenseKey($token['access_token'])
            : null;

        if (! $licenseKey) {
            Log::warning('AppSumoOAuth: could not resolve license from code');
            return redirect()->away($base.'?error=license');
        }

        // Tamper-proof, expiring hand-off token (app-key encrypted).
        $handoff = Crypt::encryptString(json_encode([
            'license_key' => $licenseKey,
            'exp'         => time() + self::TOKEN_TTL,
        ]));

        return redirect()->away($base.'?token='.urlencode($handoff));
    }

    /** POST /api/v1/appsumo/activate  {token, email, password, name?} */
    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'name'     => ['nullable', 'string', 'max:120'],
        ]);

        $licenseKey = $this->decodeHandoff($validated['token']);
        if (! $licenseKey) {
            return response()->json(['error' => 'invalid_or_expired_token'], 422);
        }

        $user = $this->appsumo->linkAndProvision(
            $licenseKey,
            $validated['email'],
            $validated['password'],
            $validated['name'] ?? null,
        );

        if (! $user) {
            return response()->json(['error' => 'license_not_found_or_deactivated'], 409);
        }

        // Account is created + provisioned; the SPA logs in with these creds.
        return response()->json(['data' => ['email' => $user->email, 'activated' => true]], 201);
    }

    private function decodeHandoff(string $token): ?string
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return null;
        }

        return $data['license_key'] ?? null;
    }
}
