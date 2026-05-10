<?php

namespace App\Http\Controllers\Api\V1\Publishing;

use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\PlatformAdapterFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialAccountController extends Controller
{
    // ── List connected accounts ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $accounts = SocialAccount::query()
            ->where('workspace_id', $user->workspace_id)
            ->orderBy('platform')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => ['accounts' => $accounts->map(fn (SocialAccount $a) => $this->serialize($a))->all()]]);
    }

    // ── Initiate OAuth ───────────────────────────────────────────────────────

    public function connect(Request $request, string $platform): JsonResponse
    {
        if (! in_array($platform, PlatformAdapterFactory::supported(), true)) {
            abort(400, "Unsupported platform: {$platform}");
        }

        /** @var User $user */
        $user = $request->user();

        // State encodes workspace + nonce — verified in callback
        $state = base64_encode(json_encode([
            'workspace_id' => $user->workspace_id,
            'nonce'        => Str::random(16),
        ]));

        // Cache state for 10 minutes to validate callback
        Cache::put("oauth_state:{$state}", $user->workspace_id, 600);

        $adapter = PlatformAdapterFactory::make($platform);

        return response()->json(['data' => ['url' => $adapter->getAuthUrl($state)]]);
    }

    // ── OAuth callback ───────────────────────────────────────────────────────

    public function callback(Request $request, string $platform): \Illuminate\Http\Response
    {
        $state = (string) $request->query('state', '');
        $code  = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        if ($error) {
            return $this->closePopup(['error' => $error]);
        }

        $workspaceId = Cache::pull("oauth_state:{$state}");

        if (! $workspaceId || ! $code) {
            return $this->closePopup(['error' => 'invalid_state']);
        }

        try {
            $adapter  = PlatformAdapterFactory::make($platform);
            $data     = $adapter->exchangeCode($code);

            SocialAccount::query()->updateOrCreate(
                ['workspace_id' => $workspaceId, 'platform' => $platform, 'platform_user_id' => $data['platform_user_id']],
                [
                    'platform_username'     => $data['platform_username'],
                    'platform_display_name' => $data['platform_display_name'],
                    'platform_avatar_url'   => $data['platform_avatar_url'],
                    'access_token'          => $data['access_token'],
                    'refresh_token'         => $data['refresh_token'],
                    'token_expires_at'      => $data['token_expires_at'],
                    'scopes'                => $data['scopes'],
                    'platform_meta'         => $data['platform_meta'],
                    'status'                => 'active',
                ],
            );

            return $this->closePopup(['connected' => $platform, 'username' => $data['platform_display_name'] ?? $data['platform_username']]);

        } catch (\Throwable $e) {
            return $this->closePopup(['error' => 'exchange_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── Disconnect ───────────────────────────────────────────────────────────

    public function destroy(Request $request, int $accountId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = SocialAccount::query()
            ->where('workspace_id', $user->workspace_id)
            ->find($accountId);

        if (! $account) {
            return $this->error('not_found', 'Account not found.', 404);
        }

        $account->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── AI caption generation ────────────────────────────────────────────────

    public function generateCaption(Request $request): JsonResponse
    {
        $request->validate([
            'export_job_id' => 'required|integer',
            'platform'      => 'required|string|in:youtube,tiktok,instagram,facebook',
        ]);

        /** @var User $user */
        $user = $request->user();

        $export = ExportJob::query()
            ->where('workspace_id', $user->workspace_id)
            ->with('project')
            ->findOrFail($request->integer('export_job_id'));

        $project  = $export->project;
        $title    = $project?->title ?? 'Untitled';
        $script   = $project?->script_text ?? '';
        $platform = $request->string('platform');

        $platformHints = match ((string) $platform) {
            'tiktok'    => 'TikTok (casual, punchy, 2–3 hashtags, under 150 chars)',
            'youtube'   => 'YouTube (engaging, keyword-rich description, no hashtags needed, 2–4 sentences)',
            'instagram' => 'Instagram (conversational, 3–5 hashtags, 1–2 sentences)',
            default     => 'a social media platform (concise and engaging)',
        };

        $scriptExcerpt = mb_substr($script, 0, 800);

        $prompt = "Write a social media caption for {$platformHints}.\n\nVideo title: {$title}\nScript excerpt: {$scriptExcerpt}\n\nReturn only the caption text. No quotes, no explanation.";

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->timeout(20)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => 'gpt-4o-mini',
                'max_tokens'  => 200,
                'temperature' => 0.8,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
            ])->throw()->json();

        $caption = trim($response['choices'][0]['message']['content'] ?? '');

        return response()->json(['data' => ['caption' => $caption]]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function serialize(SocialAccount $account): array
    {
        return [
            'id'                   => $account->getKey(),
            'platform'             => $account->platform,
            'platform_username'    => $account->platform_username,
            'platform_display_name'=> $account->platform_display_name,
            'platform_avatar_url'  => $account->platform_avatar_url,
            'status'               => $account->isTokenExpired() ? 'expired' : $account->status,
            'connected_at'         => $account->created_at?->toIso8601String(),
            'platform_meta'        => $account->platform_meta,
        ];
    }

    private function closePopup(array $data): \Illuminate\Http\Response
    {
        $json = json_encode($data);

        return response(<<<HTML
<!DOCTYPE html><html><body><script>
  try { localStorage.setItem('framecastOAuth', JSON.stringify({$json})); } catch(e) {}
  window.close();
</script></body></html>
HTML, 200, ['Content-Type' => 'text/html']);
    }
}
