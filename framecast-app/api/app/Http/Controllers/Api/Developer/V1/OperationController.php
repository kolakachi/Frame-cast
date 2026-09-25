<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\ApiQuote;
use App\Services\Developer\OperationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationController extends DeveloperController
{
    public function cancel(Request $request, string $quoteId): JsonResponse
    {
        $input = $this->validated($request, ['confirm' => ['required', 'accepted']]);
        if (! \App\Services\Developer\OperationAccounting::enabled()) {
            return $this->fail('accounting_unavailable', 'Operation cancellation is not enabled.', 409);
        }
        $quote = ApiQuote::query()->whereKey($quoteId)->where('workspace_id', $request->user()->workspace_id)->first();
        if (! $quote) return $this->fail('not_found', 'Operation not found in this workspace.', 404);
        $op = \Illuminate\Support\Facades\DB::table('api_operations')->where('quote_id', $quote->id)->latest('created_at')->first();
        if (! $op) return $this->fail('not_found', 'No accounted operation exists for this quote.', 404);
        if (! \App\Services\Developer\OperationAccounting::cancel($op->id, (int) $quote->workspace_id)) {
            return $this->fail('operation_busy', 'A request or worker is still executing. Wait for it to stop before cancelling the remaining work.', 409);
        }
        return response()->json(['data' => ['operation' => OperationStatus::forQuote($quote)], 'meta' => []]);
    }

    public function show(Request $request, string $quoteId): JsonResponse
    {
        $quote = ApiQuote::query()->whereKey($quoteId)->where('workspace_id', $request->user()->workspace_id)->first();
        if (! $quote) {
            return $this->fail('not_found', 'Operation not found in this workspace.', 404);
        }

        return response()->json(['data' => ['operation' => OperationStatus::forQuote($quote)], 'meta' => []]);
    }
}
