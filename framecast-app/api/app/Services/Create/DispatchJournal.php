<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/** A dispatch claim is never a receipt. Missing responses retain their holds. */
class DispatchJournal
{
    public function claim(string $attemptId): ?array
    {
        return DB::transaction(function () use ($attemptId) {
            $a = DB::table('composition_attempts')->where('id', $attemptId)->lockForUpdate()->firstOrFail();
            if ($a->provider_response_json) return json_decode($a->provider_response_json, true, 64, JSON_THROW_ON_ERROR);
            abort_unless($a->status === 'started' && ! $a->dispatched_at && ! $a->prediction_id, 409,
                'This call was already dispatched. Recover its receipt; do not send it again.');
            DB::table('composition_attempts')->where('id', $attemptId)->update(['dispatched_at' => now(), 'updated_at' => now()]);
            return null;
        });
    }

    public function save(string $attemptId, array $response): void
    {
        // Save before settlement/HTTP delivery. Only app-controlled provider code writes here.
        DB::table('composition_attempts')->where('id', $attemptId)->whereNull('provider_response_json')
            ->update(['provider_response_json' => json_encode($response, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }
}
