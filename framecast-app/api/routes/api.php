<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Niche\NicheController;
use App\Http\Controllers\Api\V1\Asset\AssetController;
use App\Http\Controllers\Api\V1\Sfx\SfxController;
use App\Http\Controllers\Api\V1\Approval\ApprovalController;
use App\Http\Controllers\Api\V1\Asset\CollectionController;
use App\Http\Controllers\Api\V1\Asset\ImageStyleController;
use App\Http\Controllers\Api\V1\BrandKit\BrandKitController;
use App\Http\Controllers\Api\V1\Channel\ChannelController;
use App\Http\Controllers\Api\V1\Series\SeriesController;
use App\Http\Controllers\Api\V1\Localization\LocalizationController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Api\V1\Project\CreditEstimateController;
use App\Http\Controllers\Api\V1\Scene\SceneController;
use App\Http\Controllers\Api\V1\System\FontController;
use App\Http\Controllers\Api\V1\System\HealthCheckController;
use App\Http\Controllers\Api\V1\System\NotificationController;
use App\Http\Controllers\Api\V1\System\VerificationController;
use App\Http\Controllers\Api\V1\Variant\VariantController;
use App\Http\Controllers\Api\V1\CaptionPreset\CaptionPresetController;
use App\Http\Controllers\Api\V1\VoiceProfile\VoiceProfileController;
use App\Http\Controllers\Api\V1\Character\CharacterController;
use App\Http\Controllers\Api\V1\Ugc\UgcController;
use App\Http\Controllers\Api\V1\Workspace\WorkspaceController;
use App\Http\Controllers\Api\V1\Publishing\SocialAccountController;
use App\Http\Controllers\Api\V1\Publishing\ScheduledPostController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthCheckController::class);

    // Kelviq (Merchant of Record) webhook — unauthenticated, signature-verified
    // (Svix scheme) inside the controller. Kelviq is our sole billing provider.
    Route::post('/webhooks/kelviq', \App\Http\Controllers\Api\V1\Billing\KelviqWebhookController::class);

    // AppSumo lifetime-deal (LTD) — all unauthenticated, verified inside the
    // controllers. Webhook is HMAC-signed; OAuth callback + activate finish
    // account creation for a purchased license.
    Route::post('/webhooks/appsumo', \App\Http\Controllers\Api\V1\Billing\AppSumoWebhookController::class);
    Route::get('/appsumo/oauth/callback', [\App\Http\Controllers\Api\V1\Billing\AppSumoOAuthController::class, 'callback']);
    Route::post('/appsumo/activate', [\App\Http\Controllers\Api\V1\Billing\AppSumoOAuthController::class, 'activate']);

    // Public content-report endpoint (anyone can submit, no auth required).
    // Rate-limited per-IP inside the controller. Backs the form at /report.
    Route::post('/report-content', [\App\Http\Controllers\Api\V1\Public\ReportContentController::class, 'store']);

    Route::prefix('/auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/magic-link', [AuthController::class, 'magicLink']);
        Route::get('/magic-link/verify', [AuthController::class, 'verifyMagicLink']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->middleware('auth.jwt');

        // Password reset (unauthenticated forgot-password flow)
        Route::post('/password/forgot', [AuthController::class, 'requestPasswordReset']);
        Route::get('/password/verify',  [AuthController::class, 'verifyPasswordResetToken']);
        Route::post('/password/reset',  [AuthController::class, 'resetPassword']);

        // Set / change password from inside Settings (authenticated)
        Route::post('/password/change', [AuthController::class, 'changePassword'])->middleware('auth.jwt');
    });

    // Public release notes — powers the marketing /changelog.html page.
    // No auth: a release note contains nothing private.
    Route::get('/public/changelog', [\App\Http\Controllers\Api\V1\System\ChangelogController::class, 'publicIndex']);
    // Unauthenticated: lets the register page know whether a plan is required
    // before it will accept a signup, without hardcoding the rule in the SPA.
    Route::get('/public/signup-policy', function () {
        return response()->json(['data' => [
            'require_plan' => (bool) config('billing.require_plan_on_register'),
        ], 'meta' => []]);
    });

    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->middleware('auth.jwt');

    // OAuth callbacks — unauthenticated (platform redirects here after approval)
    Route::get('/social/{platform}/callback', [SocialAccountController::class, 'callback'])->where('platform', 'youtube|tiktok|instagram|facebook');

    // Public approval review (token-gated, no auth)
    Route::get('/approve/{token}', [ApprovalController::class, 'publicShow']);
    Route::post('/approve/{token}/decide', [ApprovalController::class, 'publicDecide']);

    // Delivery events from Resend. Public because it is called by them, and
    // verified by signature rather than by session.
    Route::post('/webhooks/resend', \App\Http\Controllers\Api\V1\Webhooks\ResendWebhookController::class);

    // Affiliate portal. Public because affiliates have no user account; each
    // route resolves its own session token and is scoped to that affiliate.
    Route::post('/affiliate/portal/login', [\App\Http\Controllers\Api\V1\Affiliate\PortalController::class, 'login']);
    Route::post('/affiliate/portal/logout', [\App\Http\Controllers\Api\V1\Affiliate\PortalController::class, 'logout']);
    Route::get('/affiliate/portal/summary', [\App\Http\Controllers\Api\V1\Affiliate\PortalController::class, 'summary']);
    Route::get('/affiliate/portal/daily', [\App\Http\Controllers\Api\V1\Affiliate\PortalController::class, 'daily']);
    Route::get('/affiliate/portal/payouts', [\App\Http\Controllers\Api\V1\Affiliate\PortalController::class, 'payouts']);
    Route::post('/affiliate/portal/payment-details', [\App\Http\Controllers\Api\V1\Affiliate\PortalController::class, 'savePaymentDetails']);

    // Records an arrival from ?ref= and returns a cookie. Deliberately public:
    // affiliate traffic has no account yet, and most of it never will at the
    // moment it lands.
    Route::post('/affiliate/click', function (
        \Illuminate\Http\Request $request,
        \App\Services\Affiliate\AffiliateAttribution $attribution,
    ) {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $code = $attribution->recordClick($request, $validated['code']);

        $response = response()->json(['data' => ['tracked' => $code !== null], 'meta' => []]);
        if ($code === null) {
            return $response;
        }

        // Readable by the SPA so it can forward the code into checkout, hence
        // httpOnly false; SameSite lax so it survives the trip back from
        // Kelviq.
        return $response->cookie(
            \App\Services\Affiliate\AffiliateAttribution::COOKIE,
            $code,
            60 * 24 * \App\Services\Affiliate\AffiliateAttribution::WINDOW_DAYS,
            '/', null, true, false, false, 'lax',
        );
    });

    // Public share page for the /sample/<token> cold-DM motion.
    Route::get('/public/projects/{token}', [\App\Http\Controllers\Api\V1\Project\PublicShareController::class, 'show']);

    Route::middleware('auth.jwt')->group(function (): void {
        // Client workspaces. An agency works for several clients, each kept
        // apart, all spending the agency's one pool of credits.
        Route::get('/workspaces/clients', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'index']);
        Route::post('/workspaces/clients', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'store']);
        Route::patch('/workspaces/clients/{id}', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'update'])->whereNumber('id');
        Route::delete('/workspaces/clients/{id}', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'destroy'])->whereNumber('id');
        Route::post('/workspaces/clients/{id}/viewers', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'inviteViewer'])->whereNumber('id');
        Route::delete('/workspaces/clients/{id}/viewers/{userId}', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'removeViewer'])->whereNumber('id')->whereNumber('userId');
        Route::post('/workspaces/clients/{id}/credits', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'fund'])->whereNumber('id');
        Route::delete('/workspaces/clients/{id}/credits', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'unfund'])->whereNumber('id');
        Route::get('/workspaces/clients/{id}/viewers', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'viewers'])->whereNumber('id');
        Route::patch('/workspaces/clients/{id}/viewers/{userId}', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'updateViewer'])->whereNumber('id')->whereNumber('userId');
        Route::get('/workspaces/clients/usage', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'usage']);
        Route::post('/workspaces/switch/{id}', [\App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController::class, 'switch'])->whereNumber('id');

        Route::get('/billing/status', [BillingController::class, 'status']);
        Route::post('/billing/portal', [BillingController::class, 'portal']);
        // Kelviq (MOR) hosted checkout — returns a checkoutUrl to redirect to.
        Route::post('/billing/kelviq/checkout', [BillingController::class, 'kelviqCheckout']);
        Route::get('/me', [VerificationController::class, 'me']);
        // Frustration feedback from the rageclick prompt (and anywhere else).
        Route::post('/feedback', [\App\Http\Controllers\Api\V1\FeedbackController::class, 'store']);
        Route::post('/feedback/export', [\App\Http\Controllers\Api\V1\FeedbackController::class, 'rate']);
        // Daily streak — Spin & Win retention gamification
        Route::get('/daily-streak', [\App\Http\Controllers\Api\V1\Workspace\DailyStreakController::class, 'show']);
        Route::post('/daily-streak/claim', [\App\Http\Controllers\Api\V1\Workspace\DailyStreakController::class, 'claim']);

        // Cruise Control — chat-driven editor (see spec/CRUISE_CONTROL_PLAN.md)
        Route::post('/cruise/resolve', [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'resolve']);
        Route::post('/cruise/apply',   [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'apply']);
        Route::post('/cruise/skip',    [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'skip']);
        Route::get('/cruise/conversation/{projectId}', [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'conversation'])->whereNumber('projectId');
        Route::patch('/cruise/settings', [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'updateSettings']);
        Route::patch('/cruise/brief/{projectId}', [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'updateBrief'])->whereNumber('projectId');
        Route::post('/cruise/brief/{projectId}/refresh', [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'refreshBrief'])->whereNumber('projectId');
        Route::post('/cruise/conversation/{projectId}/reset', [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'resetConversation'])->whereNumber('projectId');
        Route::post('/cruise/undo', [\App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController::class, 'undo']);
        Route::patch('/me', [VerificationController::class, 'updateMe']);
        Route::get('/me/export', [VerificationController::class, 'exportMe']);
        Route::delete('/me', [VerificationController::class, 'deleteMe']);
        Route::get('/me/credit-history', [VerificationController::class, 'creditHistory']);
        Route::post('/verification/storage-smoke', [VerificationController::class, 'storageSmoke']);
        // Public catalog of image-gen models the user can pick. Read by the
        // editor's regen modal + wizard one-shot step's model picker.
        Route::get('/image-models', function () {
            return response()->json([
                'data' => ['models' => \App\Services\Generation\Image\ImageAdapterFactory::publicCatalog()],
                'meta' => [],
            ]);
        });

        // Lip-sync engines the spokesperson tier can run on. The editor needs
        // the per-second rate to price a clip, and shipping the formula to the
        // client instead left a copy of the pricing in JavaScript that drifted
        // the moment the server's changed.
        Route::get('/lipsync-engines', function () {
            $engines = [];
            foreach ((array) config('services.lipsync.engines', []) as $key => $engine) {
                $engines[] = [
                    'key'    => $key,
                    'label'  => $engine['label'] ?? $key,
                    'output' => $engine['output'] ?? null,
                    // Credits per second, already on the house video peg, so
                    // the client multiplies by length and nothing else.
                    'credits_per_second' => (int) ceil(
                        (float) ($engine['cost_usd_per_second'] ?? 0.14)
                        / \App\Services\CreditService::VIDEO_COGS_PER_CREDIT
                    ),
                ];
            }

            return response()->json([
                'data' => [
                    'engines' => $engines,
                    'default' => (string) config('services.lipsync.default', 'omni_human'),
                    'min_seconds' => \App\Services\CreditService::SPOKESPERSON_MIN_SECONDS,
                ],
                'meta' => [],
            ]);
        });

        // Catalog of image styles with sample thumbnail URLs (rendered by
        // `php artisan generate:style-samples` and stored in B2 at
        // style-samples/<key>.jpg). Drives the editor's style picker.
        Route::get('/image-styles', function (\App\Services\Media\StorageService $storage) {
            $styles = [];
            foreach (\App\Services\Generation\Image\ImageStyleDescriptors::META as $key => $meta) {
                $styles[] = [
                    'key'         => $key,
                    'label'       => $meta['label'],
                    'description' => $meta['description'],
                    'sample_url'  => $storage->url("style-samples/{$key}.jpg"),
                ];
            }
            return response()->json([
                'data' => ['styles' => $styles],
                'meta' => [],
            ]);
        });
        Route::get('/changelog', [\App\Http\Controllers\Api\V1\System\ChangelogController::class, 'index']);
        Route::post('/changelog/seen', [\App\Http\Controllers\Api\V1\System\ChangelogController::class, 'markSeen']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->whereNumber('notificationId');
        Route::get('/voice-profiles', [VoiceProfileController::class, 'index']);
        Route::post('/voice-profiles', [VoiceProfileController::class, 'store']);
        Route::post('/voice-profiles/clone', [VoiceProfileController::class, 'clone']);
        Route::post('/voice-profiles/preview', [VoiceProfileController::class, 'preview']);
        Route::delete('/voice-profiles/{voiceProfileId}', [VoiceProfileController::class, 'destroy'])->whereNumber('voiceProfileId');
        Route::get('/caption-presets', [CaptionPresetController::class, 'index']);
        Route::post('/caption-presets', [CaptionPresetController::class, 'store']);
        Route::delete('/caption-presets/{presetId}', [CaptionPresetController::class, 'destroy'])->whereNumber('presetId');
        Route::get('/characters', [CharacterController::class, 'index']);
        Route::post('/characters', [CharacterController::class, 'store']);
        Route::get('/characters/{characterId}', [CharacterController::class, 'show'])->whereNumber('characterId');
        Route::patch('/characters/{characterId}', [CharacterController::class, 'update'])->whereNumber('characterId');
        Route::delete('/characters/{characterId}', [CharacterController::class, 'destroy'])->whereNumber('characterId');
        Route::post('/characters/{characterId}/generate-image', [CharacterController::class, 'generateImage'])->whereNumber('characterId');
        Route::get('/character-image-generations/{generationId}', [CharacterController::class, 'generationStatus'])->whereNumber('generationId');
        Route::get('/niches', [NicheController::class, 'index']);
        Route::get('/fonts', [FontController::class, 'index']);
        Route::get('/visual-styles', [ImageStyleController::class, 'index']);
        Route::get('/image-generation/styles', [ImageStyleController::class, 'index']);
        // UGC ads — built but not released. 'internal' answers 404 for
        // everyone outside the team, so a customer who finds the route sees
        // nothing rather than a locked door.
        Route::prefix('/ugc')->middleware('internal')->group(function (): void {
            Route::get('/takes', [UgcController::class, 'takes']);
            Route::post('/plan', [UgcController::class, 'plan']);
            Route::post('/suggest', [UgcController::class, 'suggest']);
            Route::post('/quote', [UgcController::class, 'quote']);
            Route::post('/generate', [UgcController::class, 'generate']);
        });

        Route::prefix('/admin')->middleware(['admin', 'admin.ip'])->group(function (): void {
            Route::get('/affiliates', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'index']);
            Route::post('/affiliates', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'store']);
            Route::patch('/affiliates/{id}', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'update'])->whereNumber('id');
            Route::post('/affiliates/{id}/regenerate-key', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'regenerateKey'])->whereNumber('id');
            Route::get('/affiliates/{id}/conversions', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'conversions'])->whereNumber('id');
            Route::get('/affiliates/{id}/statement.csv', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'statement'])->whereNumber('id');
            Route::get('/affiliates/{id}/payouts', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'payouts'])->whereNumber('id');
            Route::get('/affiliates/{id}/payment-details', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'paymentDetails'])->whereNumber('id');
            Route::post('/affiliates/{id}/payment-details/verify', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'verifyPaymentDetails'])->whereNumber('id');
            Route::post('/affiliates/{id}/payouts/{payoutId}/status', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'updatePayoutStatus'])->whereNumber('id')->whereNumber('payoutId');
            Route::post('/affiliates/{id}/payouts', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'createPayout'])->whereNumber('id');
            Route::get('/affiliates/{id}/payouts/{payoutId}/statement.csv', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'statement'])->whereNumber('id')->whereNumber('payoutId');
            Route::post('/affiliates/{id}/payouts/{payoutId}/void', [\App\Http\Controllers\Api\V1\Admin\AffiliateController::class, 'voidPayout'])->whereNumber('id')->whereNumber('payoutId');
            Route::get('/overview', [AdminController::class, 'overview']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::get('/users/{userId}', [AdminController::class, 'userDetail'])->whereNumber('userId');
            Route::post('/users/{userId}/impersonate', [AdminController::class, 'impersonate'])->whereNumber('userId');
            Route::get('/workspaces', [AdminController::class, 'workspaces']);
            Route::patch('/workspaces/{workspaceId}/plan', [AdminController::class, 'updateWorkspacePlan'])->whereNumber('workspaceId');
            Route::patch('/workspaces/{workspaceId}/status', [AdminController::class, 'updateWorkspaceStatus'])->whereNumber('workspaceId');
            Route::get('/workspaces/{workspaceId}/credit-ledger', [AdminController::class, 'workspaceCreditLedger'])->whereNumber('workspaceId');
            Route::get('/videos', [AdminController::class, 'videos']);
            Route::get('/jobs', [AdminController::class, 'jobs']);
            Route::get('/spend-chart', [AdminController::class, 'spendChart']);
            Route::get('/audit-log', [AdminController::class, 'auditLog']);
            // Inbound billing webhooks (?provider=kelviq|appsumo).
            Route::get('/billing-webhooks', [AdminController::class, 'billingWebhooks']);
            Route::get('/failure-traces', [AdminController::class, 'failureTraces']);
            Route::get('/storage', [AdminController::class, 'storage']);

            // Admin mail — single customer or segment broadcast from hello@
            Route::get('/mail/recipients', [\App\Http\Controllers\Api\V1\Admin\AdminMailController::class, 'recipients']);
            Route::post('/mail/send', [\App\Http\Controllers\Api\V1\Admin\AdminMailController::class, 'send']);
            Route::get('/mail/history', [\App\Http\Controllers\Api\V1\Admin\AdminMailController::class, 'history']);
            Route::get('/mail/draft', [\App\Http\Controllers\Api\V1\Admin\AdminMailController::class, 'draft']);
            // POST too: an instruction can run to a paragraph, which does not
            // belong in a query string.
            Route::post('/mail/draft', [\App\Http\Controllers\Api\V1\Admin\AdminMailController::class, 'draft']);
            Route::get('/mail/log', [\App\Http\Controllers\Api\V1\Admin\AdminMailController::class, 'log']);

            // Trust & Safety — moderation events triage
            Route::get('/moderation/events', [\App\Http\Controllers\Api\V1\Admin\AdminModerationController::class, 'index']);
            Route::get('/moderation/events/{eventId}', [\App\Http\Controllers\Api\V1\Admin\AdminModerationController::class, 'show'])->whereNumber('eventId');
            Route::patch('/moderation/events/{eventId}', [\App\Http\Controllers\Api\V1\Admin\AdminModerationController::class, 'review'])->whereNumber('eventId');

            // SFX library
            Route::get('/sfx', [\App\Http\Controllers\Api\V1\Admin\AdminSfxController::class, 'index']);
            Route::post('/sfx', [\App\Http\Controllers\Api\V1\Admin\AdminSfxController::class, 'store']);
            Route::patch('/sfx/{soundId}', [\App\Http\Controllers\Api\V1\Admin\AdminSfxController::class, 'update'])->whereNumber('soundId');
            Route::delete('/sfx/{soundId}', [\App\Http\Controllers\Api\V1\Admin\AdminSfxController::class, 'destroy'])->whereNumber('soundId');
        });
        Route::prefix('/assets')->group(function (): void {
            Route::get('/orphaned', [AssetController::class, 'orphaned']);
            Route::get('/', [AssetController::class, 'index']);
            Route::post('/', [AssetController::class, 'store']);
            Route::get('/{assetId}', [AssetController::class, 'show'])->whereNumber('assetId');
            Route::patch('/{assetId}', [AssetController::class, 'update'])->whereNumber('assetId');
            Route::delete('/{assetId}', [AssetController::class, 'destroy'])->whereNumber('assetId');
        });

        // Bundled royalty-free SFX library
        Route::get('/sfx', [SfxController::class, 'index']);
        Route::post('/sfx/{soundId}/import', [SfxController::class, 'import'])->whereNumber('soundId');
        Route::prefix('/collections')->group(function (): void {
            Route::get('/', [CollectionController::class, 'index']);
            Route::post('/', [CollectionController::class, 'store']);
            Route::patch('/{collectionId}', [CollectionController::class, 'update'])->whereNumber('collectionId');
            Route::delete('/{collectionId}', [CollectionController::class, 'destroy'])->whereNumber('collectionId');
        });
        Route::prefix('/workspaces')->group(function (): void {
            Route::get('/', [WorkspaceController::class, 'index']);
            Route::post('/', [WorkspaceController::class, 'store']);
            Route::get('/{workspaceId}', [WorkspaceController::class, 'show'])->whereNumber('workspaceId');
            Route::patch('/{workspaceId}', [WorkspaceController::class, 'update'])->whereNumber('workspaceId');
            Route::delete('/{workspaceId}', [WorkspaceController::class, 'destroy'])->whereNumber('workspaceId');
        });

        Route::prefix('/series')->group(function (): void {
            Route::get('/', [SeriesController::class, 'index']);
            Route::post('/', [SeriesController::class, 'store']);
            Route::get('/{seriesId}', [SeriesController::class, 'show'])->whereNumber('seriesId');
            Route::patch('/{seriesId}', [SeriesController::class, 'update'])->whereNumber('seriesId');
            Route::delete('/{seriesId}', [SeriesController::class, 'destroy'])->whereNumber('seriesId');
            Route::get('/{seriesId}/episodes', [SeriesController::class, 'episodes'])->whereNumber('seriesId');
        });

        Route::prefix('/channels')->group(function (): void {
            Route::get('/', [ChannelController::class, 'index']);
            Route::post('/', [ChannelController::class, 'store']);
            Route::get('/{channelId}', [ChannelController::class, 'show'])->whereNumber('channelId');
            Route::patch('/{channelId}', [ChannelController::class, 'update'])->whereNumber('channelId');
            Route::delete('/{channelId}', [ChannelController::class, 'destroy'])->whereNumber('channelId');
        });

        Route::prefix('/brand-kits')->group(function (): void {
            Route::get('/', [BrandKitController::class, 'index']);
            Route::post('/', [BrandKitController::class, 'store']);
            Route::get('/{brandKitId}', [BrandKitController::class, 'show'])->whereNumber('brandKitId');
            Route::patch('/{brandKitId}', [BrandKitController::class, 'update'])->whereNumber('brandKitId');
            Route::delete('/{brandKitId}', [BrandKitController::class, 'destroy'])->whereNumber('brandKitId');
        });

        Route::post('/projects/estimate-credits', [CreditEstimateController::class, 'estimate']);
        // Dry run for an uploaded PDF — free, no rendering, no credits spent.
        Route::post('/projects/analyze-pdf', \App\Http\Controllers\Api\V1\Project\PdfAnalysisController::class);
        // Animate every scene at once. Returns a costed preview unless confirm=true.
        Route::post('/projects/{projectId}/animate-all', \App\Http\Controllers\Api\V1\Project\BulkAnimateController::class)->whereNumber('projectId');
        // Restyle every scene's image at once. Costed preview unless confirm=true.
        Route::post('/projects/{projectId}/restyle-all', \App\Http\Controllers\Api\V1\Project\BulkVisualController::class)->whereNumber('projectId');
        // Re-record every scene's voiceover. Costed preview unless confirm=true.
        Route::post('/projects/{projectId}/rerecord-all', \App\Http\Controllers\Api\V1\Project\BulkVoiceController::class)->whereNumber('projectId');

        Route::prefix('/projects')->group(function (): void {
            Route::get('/', [ProjectController::class, 'index']);
            Route::get('/queue', [ProjectController::class, 'queue']);
            Route::post('/', [ProjectController::class, 'store']);
            Route::post('/one-shot/plan', [ProjectController::class, 'planOneShot']);
            Route::post('/one-shot', [ProjectController::class, 'storeOneShot']);
            Route::get('/{projectId}', [ProjectController::class, 'show'])->whereNumber('projectId');
            Route::patch('/{projectId}', [ProjectController::class, 'update'])->whereNumber('projectId');
            Route::get('/{projectId}/exports', [ProjectController::class, 'exports'])->whereNumber('projectId');
            Route::get('/{projectId}/variants', [VariantController::class, 'index'])->whereNumber('projectId');
            Route::post('/{projectId}/variants', [VariantController::class, 'store'])->whereNumber('projectId');
            Route::get('/{projectId}/localizations', [LocalizationController::class, 'index'])->whereNumber('projectId');
            Route::post('/{projectId}/localizations', [LocalizationController::class, 'store'])->whereNumber('projectId');
            Route::post('/{projectId}/export', [ProjectController::class, 'export'])->whereNumber('projectId');
            // On-demand hook generation + scoring (C9) — re-roll ranked hook options
            Route::post('/{projectId}/hooks/generate', [ProjectController::class, 'generateHooks'])->whereNumber('projectId');
            // Toggle public share link for the /sample/<token> page
            Route::post('/{projectId}/share', [\App\Http\Controllers\Api\V1\Project\PublicShareController::class, 'toggle'])->whereNumber('projectId');
            Route::post('/{projectId}/resume-failed', [ProjectController::class, 'resumeFailed'])->whereNumber('projectId');
            Route::post('/{projectId}/retry-generation', [ProjectController::class, 'retryGeneration'])->whereNumber('projectId');
            Route::post('/{projectId}/duplicate', [ProjectController::class, 'duplicate'])->whereNumber('projectId');
            Route::delete('/{projectId}', [ProjectController::class, 'destroy'])->whereNumber('projectId');
        });

        Route::prefix('/variant-sets')->group(function (): void {
            Route::post('/{variantSetId}/export', [VariantController::class, 'export'])->whereNumber('variantSetId');
            Route::post('/{variantSetId}/retry-failed', [VariantController::class, 'retryFailed'])->whereNumber('variantSetId');
        });

        Route::delete('/variants/{variantId}', [VariantController::class, 'destroy'])->whereNumber('variantId');

        // ── Social accounts & publishing ─────────────────────────────────────
        Route::prefix('/social')->group(function (): void {
            Route::get('/accounts', [SocialAccountController::class, 'index']);
            Route::get('/{platform}/connect', [SocialAccountController::class, 'connect'])->where('platform', 'youtube|tiktok|instagram|facebook');
            // Finish a Meta connection where the grant covered several Pages.
            Route::post('/select-page', [SocialAccountController::class, 'selectPage']);
            Route::delete('/accounts/{accountId}', [SocialAccountController::class, 'destroy'])->whereNumber('accountId');
            Route::post('/generate-caption', [SocialAccountController::class, 'generateCaption']);
        });

        Route::get('/exports/completed', [ScheduledPostController::class, 'completedExports']);

        Route::prefix('/scheduled-posts')->group(function (): void {
            Route::get('/', [ScheduledPostController::class, 'index']);
            Route::post('/', [ScheduledPostController::class, 'store']);
            Route::patch('/{postId}', [ScheduledPostController::class, 'update'])->whereNumber('postId');
            Route::delete('/{postId}', [ScheduledPostController::class, 'destroy'])->whereNumber('postId');
            Route::post('/{postId}/retry', [ScheduledPostController::class, 'retry'])->whereNumber('postId');
        });
        Route::post('/localization-links/{localizationLinkId}/retry', [LocalizationController::class, 'retry'])->whereNumber('localizationLinkId');

        // Client approval links
        Route::prefix('/approvals')->group(function (): void {
            Route::get('/', [ApprovalController::class, 'index']);
            Route::post('/', [ApprovalController::class, 'store']);
            Route::delete('/{approvalId}', [ApprovalController::class, 'revoke'])->whereNumber('approvalId');
            // Decide while signed in. The public token route still exists for
            // reviewers who were only ever sent a link.
            Route::post('/{approvalId}/decide', [ApprovalController::class, 'decide'])->whereNumber('approvalId');
        });

        Route::prefix('/scenes')->group(function (): void {
            Route::post('/', [SceneController::class, 'store']);
            Route::post('/generate-draft', [SceneController::class, 'generateDraft']);
            Route::patch('/reorder', [SceneController::class, 'reorder']);
            Route::patch('/{sceneId}', [SceneController::class, 'update'])->whereNumber('sceneId');
            Route::get('/{sceneId}/preview', [SceneController::class, 'preview'])->whereNumber('sceneId');
            Route::post('/{sceneId}/regenerate-voice', [SceneController::class, 'regenerateVoice'])->whereNumber('sceneId');
            Route::post('/{sceneId}/swap-visual', [SceneController::class, 'swapVisual'])->whereNumber('sceneId');
            Route::post('/{sceneId}/generate-image', [SceneController::class, 'generateImage'])->whereNumber('sceneId');
            Route::post('/{sceneId}/animate', [SceneController::class, 'animate'])->whereNumber('sceneId');
            Route::post('/{sceneId}/animate/revert', [SceneController::class, 'revertAnimation'])->whereNumber('sceneId');
            Route::post('/{sceneId}/animate/cancel', [SceneController::class, 'cancelAnimation'])->whereNumber('sceneId');
            Route::post('/{sceneId}/animate/use-history', [SceneController::class, 'useAnimationFromHistory'])->whereNumber('sceneId');
            Route::post('/{sceneId}/regenerate-music', [SceneController::class, 'regenerateMusic'])->whereNumber('sceneId');
            Route::post('/{sceneId}/rewrite', [SceneController::class, 'rewrite'])->whereNumber('sceneId');
            Route::post('/{sceneId}/duplicate', [SceneController::class, 'duplicate'])->whereNumber('sceneId');
            Route::delete('/{sceneId}', [SceneController::class, 'destroy'])->whereNumber('sceneId');
        });
    });
});
