<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Media\StorageService;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Dry run for an uploaded PDF — what's in it, what we can read, what reading
 * the rest would cost, and what this plan allows.
 *
 * Deliberately free and side-effect-free. The extraction service's analysis
 * pass does no rendering, so the user finds out what they're buying BEFORE any
 * credits move. Without this the two bad outcomes are: silently charging for
 * vision they didn't ask for, or silently dropping a third of their document
 * and presenting the result as a video about the whole thing.
 */
class PdfAnalysisController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'asset_id' => ['required', 'integer'],
        ]);

        $asset = Asset::query()
            ->whereKey($validated['asset_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $asset) {
            return $this->error('not_found', 'That upload could not be found.', 404);
        }

        $bytes = app(StorageService::class)->get($asset->storage_url);

        if (! $bytes) {
            return $this->error('unreadable', 'That upload could not be read. Please upload it again.', 422);
        }

        $base = rtrim((string) config('services.extract.url', ''), '/');

        try {
            $response = Http::timeout(120)
                ->attach('file', $bytes, 'document.pdf')
                ->post($base.'/extract/pdf');   // no rendering — analysis only
        } catch (\Throwable $e) {
            return $this->error('analysis_unavailable', 'We could not inspect that PDF right now. Please try again.', 503);
        }

        if (in_array($response->status(), [400, 413, 422], true)) {
            return $this->error('unreadable_pdf', (string) $response->json('detail', 'That PDF could not be read.'), 422);
        }

        if (! $response->successful()) {
            return $this->error('analysis_failed', 'We could not inspect that PDF right now. Please try again.', 502);
        }

        $pageCount = (int) $response->json('page_count', 0);
        $counts    = (array) $response->json('counts', []);
        $scanned   = (int) ($counts['scanned'] ?? 0);

        $plan       = WorkspaceUsageService::plans()[$user->workspace?->plan_tier ?? 'free'] ?? [];
        $limits     = CreditService::PLAN_LIMITS[$user->workspace?->plan_tier ?? 'free'] ?? CreditService::PLAN_LIMITS['free'];
        $pageLimit  = $limits['pdf_page_limit'] ?? null;
        $visionCap  = $limits['pdf_vision_page_limit'] ?? 0;

        // Pages we'd read at all, and of those, how many need vision.
        $readablePages   = $pageLimit === null ? $pageCount : min($pageCount, $pageLimit);
        $overLimit       = $pageCount > $readablePages;
        $visionCandidates = min($scanned, $visionCap === null ? $scanned : $visionCap);
        $visionCost      = $visionCandidates * CreditService::PDF_VISION_PAGE;

        return response()->json([
            'data' => [
                'page_count'      => $pageCount,
                'counts'          => $counts,
                'readable_pages'  => $readablePages,
                'over_limit'      => $overLimit,
                'plan' => [
                    'name'               => $plan['name'] ?? 'Free',
                    'page_limit'         => $pageLimit,
                    'vision_page_limit'  => $visionCap,
                ],
                'vision' => [
                    // How many scanned pages we could read, after the plan cap.
                    'pages'            => $visionCandidates,
                    'credits'          => $visionCost,
                    'credits_per_page' => CreditService::PDF_VISION_PAGE,
                    // Scanned pages the plan won't cover even if they say yes.
                    'beyond_plan'      => max(0, $scanned - $visionCandidates),
                ],
            ],
            'meta' => [],
        ]);
    }
}
