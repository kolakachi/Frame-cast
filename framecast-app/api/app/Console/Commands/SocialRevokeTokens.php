<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Publishing\SocialTokenRevoker;
use Illuminate\Console\Command;

/**
 * Revoke connected accounts' tokens at their platforms and ask the users to reconnect (L16 incident, 2026-10-08: the
 * tokens were readable in database dumps that were briefly public). Lists by default; --confirm revokes. A token is
 * wiped only after its platform confirms it is revoked or already invalid. Prints status codes, never a token.
 */
class SocialRevokeTokens extends Command
{
    protected $signature = 'social:revoke-tokens {--account=* : Account ids (default: every account)} {--confirm : Revoke, wipe and mark for reconnection}';

    protected $description = 'Revoke the tokens of connected social accounts at their platforms and mark them "Reconnect needed"';

    public function handle(SocialTokenRevoker $revoker): int
    {
        $ids = array_map('intval', (array) $this->option('account'));
        $accounts = SocialAccount::query()->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();
        $failed = 0;
        foreach ($accounts as $a) {
            $row = ['id' => $a->id, 'workspace' => $a->workspace_id, 'platform' => $a->platform, 'status' => $a->status];
            if (! $this->option('confirm')) { $this->line(json_encode($row + ['action' => 'would revoke'])); continue; }
            try {
                $r = $revoker->revokeAndWipe($a);
                $failed += $r['revoked'] ? 0 : 1;
                $this->line(json_encode($row + $r + ['result' => $r['revoked'] ? 'revoked, wiped, reconnect needed' : 'NOT revoked: kept as is']));
            } catch (\Throwable $e) {
                $failed++;
                $this->line(json_encode($row + ['result' => 'error: '.class_basename($e)]));
            }
        }
        if (! $this->option('confirm')) $this->info($accounts->count().' account(s). Add --confirm to revoke them.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
