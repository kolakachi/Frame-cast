<?php

namespace App\Services\Launch;

use App\Models\LaunchPurchase;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\RewardService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Launchpad launch buyers (owner, 2026-10-12). The only writer of the launch_* tiers and of workspaces.plan_addons.
 *
 * Every sale arrives as grant(email, product, receipt): from the Launchpad IPN once its spec is in, or from an admin
 * until then. A buyer with no account gets one. A workspace on free, the UGC Test Pass or a launch tier moves to the
 * highest launch tier it owns; any other plan (AppSumo, lifetime, a subscription) keeps its tier and the launch
 * products only add on top, never take away. Each product's credits are granted once, per receipt.
 */
class LaunchpadService
{
    /** Tiers a launch purchase may replace. Anything else was bought elsewhere and stays. */
    private const REPLACEABLE = ['free', 'ugc_pass'];

    public function __construct(private CreditService $credits)
    {
    }

    /**
     * Record a sale and apply it. Idempotent per receipt and product: a repeat returns the existing row unchanged.
     *
     * @return array{purchase: LaunchPurchase, created: bool}
     */
    public function grant(string $email, ?string $name, string $product, string $receipt, array $sale = [], string $source = 'launchpad'): array
    {
        $catalogue = LaunchCatalogue::product($product);
        if ($catalogue === null) {
            throw new \InvalidArgumentException("Unknown launch product: {$product}");
        }
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A launch sale needs a valid email.');
        }

        $existing = LaunchPurchase::query()->where('receipt', $receipt)->where('product', $product)->first();
        if ($existing) {
            return ['purchase' => $existing, 'created' => false];
        }

        $workspace = $this->workspaceFor($email, $name);

        try {
            $purchase = DB::transaction(function () use ($workspace, $email, $product, $receipt, $sale, $source, $catalogue) {
                $purchase = LaunchPurchase::query()->create([
                    'workspace_id'    => $workspace->getKey(),
                    'product'         => $product,
                    'receipt'         => $receipt,
                    'source'          => $source,
                    'email'           => $email,
                    'status'          => 'active',
                    'credits_granted' => (int) ($catalogue['credits'] ?? 0),
                    'amount'          => $sale['amount'] ?? null,
                    'currency'        => $sale['currency'] ?? null,
                    'payload'         => $sale['payload'] ?? null,
                ]);
                $this->recompute($workspace);
                if ($purchase->credits_granted > 0) {
                    $this->credits->grant((int) $workspace->getKey(), $purchase->credits_granted, 'launch_'.$product);
                }

                return $purchase;
            });
        } catch (QueryException $e) {
            // Two deliveries of one sale raced past the check above; the unique key let only one in.
            $again = LaunchPurchase::query()->where('receipt', $receipt)->where('product', $product)->first();
            if ($again) {
                return ['purchase' => $again, 'created' => false];
            }
            throw $e;
        }

        Log::info('Launchpad: product granted', ['workspace_id' => $workspace->getKey(), 'product' => $product, 'receipt' => $receipt, 'source' => $source]);
        \App\Services\Onboarding\WelcomeMail::sendOnce($workspace->fresh());

        return ['purchase' => $purchase, 'created' => true];
    }

    /**
     * A refund or chargeback (owner, 2026-10-12: revoke that product): its features go, and the credits it granted
     * come back out of what is unspent. Refunding the front end suspends the account when nothing else holds it.
     * Without a product, every product on the receipt is revoked.
     *
     * @return list<LaunchPurchase>
     */
    public function revoke(string $receipt, ?string $product = null, string $status = 'refunded'): array
    {
        $rows = LaunchPurchase::query()->where('receipt', $receipt)->where('status', 'active')
            ->when($product !== null, fn ($q) => $q->where('product', $product))->get();
        $done = [];
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $status, &$done) {
                $workspace = Workspace::query()->whereKey($row->workspace_id)->lockForUpdate()->firstOrFail();
                $take = $this->credits->forfeitTopup($workspace, $row->credits_granted, 'launch_'.$status,
                    ['product' => $row->product, 'receipt' => $row->receipt]);
                $row->forceFill(['status' => $status, 'credits_revoked' => $take, 'revoked_at' => now()])->save();
                $this->recompute($workspace);
                $workspace->refresh();
                $owned = LaunchPurchase::query()->where('workspace_id', $workspace->getKey())->where('status', 'active')->exists();
                if ($row->product === 'fe' && $workspace->plan_source === 'launchpad' && ! $owned) {
                    $workspace->forceFill(['status' => 'suspended', 'plan_status' => 'deactivated'])->save();
                }
                $done[] = $row->fresh();
            });
            Log::info('Launchpad: product revoked', ['workspace_id' => $row->workspace_id, 'product' => $row->product, 'receipt' => $receipt, 'status' => $status]);
        }

        return $done;
    }

    /**
     * Re-derive the workspace's launch tier and plan_addons from the products it owns now. A workspace whose plan
     * came from somewhere else keeps its tier; one that was only ever on launch products falls back to free when it
     * owns none.
     */
    public function recompute(Workspace $workspace): void
    {
        $owned = LaunchPurchase::query()->where('workspace_id', $workspace->getKey())->where('status', 'active')
            ->orderBy('id')->pluck('product')->all();
        $combined = LaunchCatalogue::combine($owned);
        $current = (string) ($workspace->getAttributes()['plan_tier'] ?? 'free');
        $ours = in_array($current, self::REPLACEABLE, true) || LaunchCatalogue::isLaunchTier($current);

        $fill = ['plan_addons' => $owned === [] ? null : $combined['limits']];
        if ($ours && $combined['tier'] !== null) {
            $fill += ['plan_tier' => $combined['tier'], 'plan_source' => 'launchpad', 'plan_status' => 'active', 'plan_renews_at' => null, 'credits_monthly' => 0];
        } elseif ($ours && LaunchCatalogue::isLaunchTier($current)) {
            $fill += ['plan_tier' => 'free'];
        }
        $workspace->forceFill($fill)->save();
    }

    /** The buyer's workspace, or a new account for a first-time buyer (mirrors registration; they sign in by magic link). */
    private function workspaceFor(string $email, ?string $name): Workspace
    {
        return DB::transaction(function () use ($email, $name) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->with('workspace')->lockForUpdate()->first();
            if ($user?->workspace) {
                return $user->workspace;
            }
            $label = Str::of($email)->before('@')->headline();
            $workspace = Workspace::query()->create(['name' => $label.' Workspace', 'plan_tier' => 'free', 'status' => 'active']);
            $user = User::query()->create([
                'workspace_id'            => $workspace->getKey(),
                'name'                    => trim((string) $name) !== '' ? trim((string) $name) : $label->value(),
                'email'                   => $email,
                'password_hash'           => null,
                'timezone'                => 'UTC',
                'role'                    => 'owner',
                'status'                  => 'active',
                'onboarding_step'         => 1,
                'onboarding_last_sent_at' => now(),
            ]);
            $workspace->forceFill(['owner_user_id' => $user->getKey(), 'acquisition_channel' => 'launchpad'])->save();
            rescue(fn () => app(RewardService::class)->ensureReferralCode($workspace));
            Log::info('Launchpad: provisioned account', ['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey()]);

            return $workspace->fresh();
        });
    }
}
