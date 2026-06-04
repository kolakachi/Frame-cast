<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\Moderation\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public endpoint behind /api/v1/report-content. Anyone can submit a report
 * (no auth required) — that's the policy promise on /synthetic-media and
 * /acceptable-use ("anyone, including non-users, can report"). Throttled
 * per-IP to keep this from being a spam vector.
 */
class ReportContentController extends Controller
{
    public function store(Request $request, ModerationService $moderation): JsonResponse
    {
        // Light per-IP throttle so the public form can't be turned into
        // an email-bomb. 5 reports per hour per IP is well above any
        // legitimate reporter and well below spam scale.
        $ip = (string) $request->ip();
        $rateKey = 'report-content:' . sha1($ip);
        $count = (int) Cache::get($rateKey, 0);
        if ($count >= 5) {
            return response()->json([
                'error' => [
                    'code'    => 'rate_limited',
                    'message' => 'Too many reports from this address. Please email hello@wyvstudio.com if this is in error.',
                ],
            ], 429);
        }
        Cache::put($rateKey, $count + 1, now()->addHour());

        $validated = $request->validate([
            'url'            => ['required', 'string', 'max:1000'],
            'violation_type' => ['required', 'string', 'in:csam,nonconsensual_sexual,deepfake_impersonation,public_figure,hate_violence,misinformation,fraud_scam,copyright,trademark,other'],
            'message'        => ['required', 'string', 'min:10', 'max:4000'],
            'email'          => ['nullable', 'email', 'max:255'],
        ]);

        $moderation->recordUserReport([
            'url'            => $validated['url'],
            'violation_type' => $validated['violation_type'],
            'message'        => $validated['message'],
            'email'          => $validated['email'] ?? null,
            'user_agent'     => $request->userAgent(),
            'ip_hash'        => hash('sha256', $ip . config('app.key')),
            'referrer'       => $request->headers->get('referer'),
        ]);

        return response()->json([
            'data' => [
                'received' => true,
                'message'  => 'Thanks. A human reviews every report within five business days. We will reply if we need more information.',
            ],
        ]);
    }
}
